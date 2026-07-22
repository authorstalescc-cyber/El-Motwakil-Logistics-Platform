<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\App;
use App\Core\Auth;
use App\Models\Track;

/**
 * تتبع الشحنات باللوكيشن (v33).
 *
 * الكنترولر ده فيه نوعين من الـ actions — خلّي بالك من الفرق:
 *
 *  (أ) صفحات المكتب  (/tracking…)  → requireAuth() + requireCsrf() زي أي وحدة.
 *  (ب) المسار العام  (/t/{token}…) → **بلا auth وبلا CSRF عن قصد**، لأن السائق
 *      بيفتحه من موبايله من غير حساب. التوكن العشوائي هو الصلاحية الوحيدة:
 *        - مربوط برحلة واحدة بس (ما يشوفش أي شحنة تانية).
 *        - له تاريخ انتهاء + حالة (يتقفل لما الرحلة تخلص).
 *        - الصفحة ما تعرضش أي مبلغ إطلاقًا — بيانات الشحنة الوصفية بس.
 *        - الاستقبال محدود المعدّل داخل Track::addPing.
 *
 * ⚠️ الوحدة معزولة: لا تكتب في أي جدول مالي ولا تمسّ العهدة/التقفيل/المصروفات.
 */
class TrackController extends Controller
{
    // ========================================================================
    //  (أ) صفحات المكتب
    // ========================================================================

    /** لوحة التتبع: خريطة بكل الشحنات المتحرّكة + كروت */
    public function index(): void
    {
        $this->requireAuth();
        $status = $_GET['status'] ?? 'active';
        if (!isset(Track::STATUSES[$status]) && $status !== 'all') { $status = 'active'; }
        $q = trim((string) ($_GET['q'] ?? ''));

        $tracks = Track::listing($status === 'all' ? '' : $status, $q);

        $this->render('tracking/index', [
            'title'   => 'تتبع الشحنات',
            'tracks'  => $tracks,
            'counts'  => Track::counts(),
            'status'  => $status,
            'q'       => $q,
            'base'    => public_base_url(),
        ]);
    }

    /** اختيار الشحنة (البوليصة) اللي هنولّد لها رابط */
    public function pick(): void
    {
        $this->requireAuth();
        $q = trim((string) ($_GET['q'] ?? ''));
        $this->render('tracking/pick', [
            'title' => 'رابط تتبع جديد',
            'bills' => Track::trackableBills($q),
            'q'     => $q,
            'base'  => public_base_url(),
        ]);
    }

    /** توليد الرابط */
    public function store(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $billId = (int) ($_POST['bill_id'] ?? 0);
        if ($billId <= 0) {
            flash('error', 'اختار الشحنة الأول.');
            redirect('tracking/new');
        }
        try {
            $t = Track::createForBill(
                $billId,
                (string) ($_POST['driver_phone'] ?? ''),
                (string) ($_POST['note'] ?? ''),
                Auth::user()
            );
        } catch (\Throwable $e) {
            flash('error', 'تعذّر توليد الرابط: ' . $e->getMessage());
            redirect('tracking/new');
            return;   // redirect() بيخرج فعليًا — للتوضيح بس
        }
        flash('success', 'الرابط جاهز — ابعته للسائق على الواتساب.');
        redirect('tracking/' . (int) $t['id']);
    }

    /** صفحة الرحلة: الخريطة + خط السير + سجل النقاط */
    public function show(string $id): void
    {
        $this->requireAuth();
        $t = Track::find((int) $id);
        if (!$t) { $this->notFound(); return; }

        $bill = Database::row('SELECT * FROM bawalis WHERE id = ?', [(int) $t['bill_id']]);

        $this->render('tracking/show', [
            'title'   => 'تتبع الشحنة' . (!empty($t['driver']) ? ' — ' . $t['driver'] : ''),
            't'       => $t,
            'bill'    => $bill,
            'pings'   => Track::pings((int) $t['id'], 'ASC'),
            'summary' => Track::summary((int) $t['id']),
            'link'    => Track::publicLink($t),
            'base'    => public_base_url(),
            'eta'     => Track::eta($t, $bill['destination'] ?? null),
        ]);
    }

    /** تحديث حيّ للخريطة (JSON) — الصفحة بتنادي ده كل شوية */
    public function feed(string $id): void
    {
        $this->requireAuth();
        $t = Track::find((int) $id);
        if (!$t) { http_response_code(404); $this->json(['ok' => false]); return; }

        $pings = array_map(fn($p) => [
            'lat'  => (float) $p['lat'],
            'lng'  => (float) $p['lng'],
            'acc'  => $p['accuracy_m'] !== null ? (int) $p['accuracy_m'] : null,
            'addr' => $p['address'] ?? '',
            'src'  => $p['source'],
            'at'   => date('H:i', strtotime($p['created_at'])),
            'date' => date('Y-m-d', strtotime($p['created_at'])),
        ], Track::pings((int) $t['id'], 'ASC'));

        [$pulseKey, $pulseTxt] = Track::pulse($t);
        $this->json([
            'ok'      => true,
            'pings'   => $pings,
            'count'   => count($pings),
            'pulse'   => $pulseKey,
            'pulseTxt'=> $pulseTxt,
            'summary' => Track::summary((int) $t['id']),
        ]);
    }

    /** كل المواقع الحالية للوحة (JSON) — لتحديث خريطة الصفحة الرئيسية */
    public function feedAll(): void
    {
        $this->requireAuth();
        $out = [];
        foreach (Track::listing('active', '') as $t) {
            if ($t['last_lat'] === null) { continue; }
            [$pk, $ptxt] = Track::pulse($t);
            $out[] = [
                'id'     => (int) $t['id'],
                'lat'    => (float) $t['last_lat'],
                'lng'    => (float) $t['last_lng'],
                'driver' => $t['driver'] ?: '—',
                'car'    => $t['car_no'] ?: '',
                'bill'   => $t['bill_number'] ?: '',
                'dest'   => $t['destination'] ?: '',
                'addr'   => $t['last_address'] ?: '',
                'ago'    => Track::ago($t['last_ping_at']),
                'pulse'  => $pk,
                'url'    => url('tracking/' . (int) $t['id']),
            ];
        }
        $this->json(['ok' => true, 'cars' => $out]);
    }

    public function setStatus(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $t = Track::find((int) $id);
        if (!$t) { $this->notFound(); return; }

        $st = (string) ($_POST['status'] ?? '');
        if (!isset(Track::STATUSES[$st])) {
            flash('error', 'حالة غير معروفة.');
            redirect('tracking/' . (int) $id);
        }
        Track::setStatus((int) $id, $st);
        flash('success', 'الحالة اتغيّرت لـ «' . Track::STATUSES[$st] . '».');
        redirect('tracking/' . (int) $id);
    }

    /** يمدّ الصلاحية ويجدّد الرابط على الدومين العام الحالي */
    public function renew(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        if (!Track::find((int) $id)) { $this->notFound(); return; }

        if (public_base_url() === '') {
            flash('error', 'مفيش رابط عام دلوقتي — شغّل «تشغيل عام على النت» الأول.');
            redirect('tracking/' . (int) $id);
        }
        Track::renew((int) $id);
        flash('success', 'الرابط اتجدّد — ابعته للسائق تاني.');
        redirect('tracking/' . (int) $id);
    }

    public function updatePhone(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $t = Track::find((int) $id);
        if (!$t) { $this->notFound(); return; }

        Track::setPhone((int) $id, (string) ($_POST['driver_phone'] ?? ''), (string) ($t['driver'] ?? ''));
        flash('success', 'الرقم اتحفظ.');
        redirect('tracking/' . (int) $id);
    }

    public function destroy(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        if (!Track::find((int) $id)) { $this->notFound(); return; }
        Track::delete((int) $id);
        flash('success', 'التتبع اتمسح (الشحنة نفسها ما اتلمستش).');
        redirect('tracking');
    }

    // ------------------------------------------------------------------------
    //  نقاط الوجهات (v34) — إحداثيات مرجعية تُحسب منها المسافة والوصول المتوقع
    // ------------------------------------------------------------------------

    public function places(): void
    {
        $this->requireAuth();
        $this->render('tracking/places', [
            'title'    => 'وجهات الخريطة',
            'points'   => Track::points(),
            'coverage' => Track::destinationCoverage(),
            'edit'     => isset($_GET['edit']) ? Track::findPoint((int) $_GET['edit']) : null,
            'prefill'  => trim((string) ($_GET['name'] ?? '')),
        ]);
    }

    public function savePlace(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        try {
            Track::savePoint(
                (int) ($_POST['id'] ?? 0),
                (string) ($_POST['name'] ?? ''),
                (float) ($_POST['lat'] ?? 0),
                (float) ($_POST['lng'] ?? 0),
                (string) ($_POST['aliases'] ?? ''),
                (string) ($_POST['note'] ?? '')
            );
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('tracking/places');
            return;
        }
        flash('success', 'الوجهة اتحفظت — المسافات هتتحسب عليها من دلوقتي.');
        redirect('tracking/places');
    }

    public function deletePlace(string $pid): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        Track::deletePoint((int) $pid);
        flash('success', 'الوجهة اتمسحت (البوالص ما اتلمستش).');
        redirect('tracking/places');
    }

    /**
     * «حدّدها تلقائيًا» — يدوّر على وجهة واحدة في OpenStreetMap ويحفظها.
     * الفلتر الصارم في Track::geocodePlace بيرفض النتايج الغلط بدل ما يحفظ رقم مضلّل.
     */
    /**
     * بحث بالاسم من صفحة الوجهات — يرجّع إحداثيات JSON بلا حفظ.
     *
     * ⚠️ الطلب ده كان بيتبعت **من متصفح المالك مباشرة** لـNominatim، وده كان
     *    فيه مشكلتين: (1) الـCSP الجديدة (`connect-src 'self'`) بتمنع أي اتصال
     *    خارجي من الصفحة فالزرار وقف عن الشغل؛ (2) الأهم — الكود القديم كان
     *    بياخد **أول نتيجة خام** (`j[0]`)، وده بالظبط الفخ الموثَّق: البحث عن
     *    «العاشر» بيرجّع «مقبرة رمسيس العاشر» في الأقصر، و«6 أكتوبر» بترجّع
     *    كافيه في الإسكندرية. `Track::geocodePlace()` فيها الفلترة الصح
     *    (نوع المكان الأول مش الـimportance) — فبنعدّي عليها.
     */
    public function lookupPlace(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $this->json(['ok' => false, 'msg' => 'اكتب اسم الوجهة الأول.']);
            return;
        }
        $hit = Track::geocodePlace($name);
        if (!$hit) {
            $this->json(['ok' => false, 'msg' => 'مالقيناش الاسم ده بشكل موثوق — حدّده بإيدك على الخريطة.']);
            return;
        }
        $this->json([
            'ok'    => true,
            'lat'   => (float) $hit['lat'],
            'lng'   => (float) $hit['lng'],
            'label' => (string) ($hit['label'] ?? $name),
        ]);
    }

    public function autoPlace(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            flash('error', 'اكتب اسم الوجهة الأول.');
            redirect('tracking/places');
            return;
        }
        $r = Track::learnDestination($name);
        if (!$r) {
            flash('error', 'مالقيناش «' . $name . '» في الخرائط بشكل موثوق — حدّدها بإيدك على الخريطة.');
            redirect('tracking/places?name=' . urlencode($name));
            return;
        }
        flash('success', 'اتحدّدت «' . $r['point']['name'] . '» تلقائيًا — ابصّ عليها على الخريطة واتأكد.');
        redirect('tracking/places?edit=' . (int) $r['point']['id']);
    }

    // ========================================================================
    //  (ب) المسار العام — السائق. بلا auth وبلا CSRF عن قصد (التوكن هو الصلاحية)
    // ========================================================================

    /** صفحة السائق: زرار كبير «ابعت موقعي» + تتبع مباشر اختياري */
    public function driver(string $token): void
    {
        $t = Track::findByToken($this->cleanToken($token));
        if (!$t) {
            http_response_code(404);
            $this->render('tracking/driver_closed', [
                'reason' => 'الرابط ده مش صحيح. اتأكد إنك نسخته كامل، أو اطلب رابط جديد من المكتب.',
            ], false);
            return;
        }

        Track::markOpened((int) $t['id']);
        $bill = Database::row(
            'SELECT bill_number, container_no, container_type, container_count, destination
               FROM bawalis WHERE id = ?',
            [(int) $t['bill_id']]
        );

        if (!Track::isOpen($t)) {
            $this->render('tracking/driver_closed', ['reason' => Track::closedReason($t)], false);
            return;
        }

        $this->render('tracking/driver', [
            't'        => $t,
            'bill'     => $bill,
            'last'     => Track::lastPing((int) $t['id']),
            'liveSecs' => max(10, (int) App::setting('track_live_secs', 30)),
            'company'  => App::setting('company_name', 'شركة المتوكل'),
            'postUrl'  => url('t/' . $t['token'] . '/ping'),
        ], false);   // بلا layout — صفحة موبايل مستقلة بلا قائمة النظام
    }

    /**
     * استقبال الموقع (JSON). بلا CSRF — التوكن في الرابط هو الصلاحية.
     * بيرد دايمًا JSON نظيف عشان صفحة السائق ما تتكسرش.
     */
    public function ping(string $token): void
    {
        // درع الرد: أي تحذير/إشعار PHP طايش (deprecated، notice…) لو اتطبع قبل
        // الـ JSON هيكسر الرد على موبايل السائق ويوريه «النت ضعيف» غلط.
        // بنلتقط كل المخرجات، نقصّ أي زبالة قبل أول '{'، ونطلّع الـ JSON نضيف.
        ob_start();
        register_shutdown_function(static function (): void {
            if (ob_get_level() === 0) { return; }
            $out = (string) ob_get_clean();
            $at  = strpos($out, '{');
            if ($at === false) {
                // مفيش JSON خالص (خطأ قاتل مثلًا) — نرد رسالة نظيفة بدل صفحة خطأ
                error_log('[track/ping] مفيش رد JSON. المخرجات: ' . mb_substr($out, 0, 300));
                echo json_encode(['ok' => false, 'msg' => 'حصلت مشكلة — حاول تاني.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            if ($at > 0) {   // فيه تحذير قبل الـ JSON — نقصّه ونسجّله
                error_log('[track/ping] مخرجات طايشة اتقصّت: ' . mb_substr(substr($out, 0, $at), 0, 300));
            }
            echo substr($out, $at);
        });
        header('Content-Type: application/json; charset=utf-8');

        $t = Track::findByToken($this->cleanToken($token));
        if (!$t) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'msg' => 'الرابط مش صحيح.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // الجسم JSON من fetch (مش form) — وبديل form-data لو الـ JS فشل
        $raw  = file_get_contents('php://input');
        $body = json_decode((string) $raw, true);
        if (!is_array($body)) { $body = $_POST; }

        $lat = isset($body['lat']) ? (float) ar2en_digits((string) $body['lat']) : null;
        $lng = isset($body['lng']) ? (float) ar2en_digits((string) $body['lng']) : null;
        if ($lat === null || $lng === null) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'msg' => 'مفيش إحداثيات.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $r = Track::addPing($t, $lat, $lng, [
                'source'   => (string) ($body['source'] ?? 'manual'),
                'accuracy' => $body['accuracy'] ?? '',
                'speed'    => $body['speed'] ?? '',
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'msg' => 'حصلت مشكلة — حاول تاني.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!$r['ok']) {
            echo json_encode(['ok' => false, 'msg' => $r['msg']], JSON_UNESCAPED_UNICODE);
            return;
        }
        $p = $r['ping'] ?? [];
        echo json_encode([
            'ok'      => true,
            'msg'     => $r['msg'],
            'time'    => date('H:i', strtotime($p['created_at'] ?? 'now')),
            'address' => $p['address'] ?? '',
        ], JSON_UNESCAPED_UNICODE);
    }

    // ------------------------------------------------------------------------

    /** التوكن hex بس — أي حاجة تانية تُرفض قبل ما توصل للقاعدة */
    private function cleanToken(string $token): string
    {
        return preg_match('/^[a-f0-9]{6,24}$/i', $token) ? strtolower($token) : '';
    }

    private function notFound(): void
    {
        http_response_code(404);
        flash('error', 'التتبع ده مش موجود.');
        redirect('tracking');
    }
}
