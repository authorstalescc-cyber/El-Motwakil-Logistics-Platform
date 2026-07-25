<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\FormState;
use App\Core\RepAuth;
use App\Core\WebPush;
use App\Models\PushNotify;
use App\Models\PushSubscription;
use App\Models\Rep;

/**
 * إشعارات التطبيق — الاشتراك والإرسال.
 *
 * ⚠️ **`subscribe`/`unsubscribe` بيقبلوا جلستين مختلفتين**: جلسة المكتب
 *    (`Auth`) وجلسة المندوب (`RepAuth`) — ودي أول نقطة في النظام بتخدم
 *    الاتنين، عشان ما نكتبش نفس المسار مرتين. الجمهور بيتحدّد **من الجلسة
 *    نفسها** مش من الفورم: المندوب مستحيل يسجّل نفسه كجهاز مكتب.
 *
 * ⚠️ **الإرسال (`send`) للمالك والمدير بس** — مفروض في `Access` مركزيًّا.
 */
class PushController extends Controller
{
    /** شاشة الإشعارات: تفعيل الجهاز + إرسال إشعار + سجل المرسَل. */
    public function index(): void
    {
        $this->requireAuth();
        $this->requireRole(['owner', 'manager']);
        // لو الإرسال فشل، المكتوب بيرجع في مكانه بدل ما المالك يعيد كتابته
        $state = FormState::consume('push.send', 'push');
        $this->render('push/index', [
            'title'    => 'الإشعارات',
            'old'      => $state['old'],
            'errors'   => $state['errors'],
            'formToken'=> $state['token'],
            'back'     => $state['back'],
            'ready'    => WebPush::available(),
            'counts'   => PushSubscription::counts(),
            'devices'  => PushSubscription::listing(),
            'log'      => PushNotify::recent(30),
            'reps'     => Rep::all('', 'active'),
            'pubKey'   => WebPush::publicKey(),
        ]);
    }

    /** المفتاح العام — الواجهة محتاجاه عشان تعمل اشتراك. */
    public function key(): void
    {
        if (!Auth::check() && !RepAuth::check()) {
            http_response_code(401);
            $this->json(['ok' => false, 'msg' => 'لازم تسجّل دخول الأول.']);
            return;
        }
        $this->json(['ok' => WebPush::available(), 'key' => WebPush::publicKey()]);
    }

    /** تسجيل الجهاز الحالي. */
    public function subscribe(): void
    {
        [$audience, $userName, $repId] = $this->who();
        $this->requireCsrf();

        $raw = json_decode((string) file_get_contents('php://input'), true);
        $sub = is_array($raw) ? $raw : $_POST;
        $data = [
            'endpoint' => (string) ($sub['endpoint'] ?? ''),
            'p256dh'   => (string) ($sub['p256dh'] ?? ''),
            'auth'     => (string) ($sub['auth'] ?? ''),
        ];
        try {
            PushSubscription::save($data, $audience, $userName, $repId, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
        $this->json(['ok' => true, 'msg' => 'الإشعارات اتفعّلت على الجهاز ده.']);
    }

    /** إلغاء اشتراك الجهاز الحالي. */
    public function unsubscribe(): void
    {
        $this->who();
        $this->requireCsrf();
        $raw = json_decode((string) file_get_contents('php://input'), true);
        $endpoint = (string) (($raw['endpoint'] ?? null) ?? ($_POST['endpoint'] ?? ''));
        PushSubscription::remove($endpoint);
        $this->json(['ok' => true, 'msg' => 'اتقفلت الإشعارات على الجهاز ده.']);
    }

    /** إرسال إشعار مكتوب باليد. */
    public function send(): void
    {
        $this->requireAuth();
        $this->requireRole(['owner', 'manager']);
        $this->requireCsrf();

        $title = trim((string) ($_POST['title'] ?? ''));
        $body  = trim((string) ($_POST['body'] ?? ''));
        $aud   = (string) ($_POST['audience'] ?? 'all');
        $url   = trim((string) ($_POST['url'] ?? ''));

        $errors = [];
        if ($title === '') { $errors['title'] = 'اكتب عنوان الإشعار.'; }
        if ($body === '')  { $errors['body']  = 'اكتب نص الإشعار.'; }
        if (!WebPush::available()) { $errors['_form'] = 'الإشعارات مش جاهزة على السيرفر — شغّل php bin/migrate_v51.php.'; }

        // الجمهور من قايمة مقفولة — مش أي نص جاي من الفورم
        $allowed = ['all', 'office', 'rep'];
        if (!in_array($aud, $allowed, true) && !preg_match('/^rep:\d+$/', $aud)) { $errors['audience'] = 'اختر جمهورًا صحيحًا.'; }

        $subs = $errors ? [] : PushSubscription::forAudience($aud);
        if (!$errors && !$subs) {
            $errors['_form'] = 'مفيش أي جهاز مفعّل الإشعارات في الفئة دي لسه.';
        }
        if ($errors) {
            $this->formFailure('push.send', 'push', $_POST, $errors, 'push');
        }

        $this->claimForm('push');
        PushNotify::custom($title, $body, $aud, $url !== '' ? $url : null, (string) Auth::user());
        $this->done('اتبعت الإشعار لـ' . count($subs) . ' جهاز.', 'push');
    }

    /**
     * إشعار تجريبي — بيروح **لكل الأجهزة المفعّلة** (مكتب ومناديب) وبيرجّع
     * النتيجة الحقيقية.
     *
     * ⚠️ كان بيروح لأجهزة المكتب بس وبيقول «اتبعت» حتى لو مفيش ولا جهاز —
     *    فالمالك فعّل الإشعارات على موبايله من تطبيق المندوب، وضغط تجربة من
     *    المكتب، وما وصلوش حاجة **بلا أي رسالة تشرح**. التجربة دلوقتي بتقول
     *    الرقم الحقيقي، ولو صفر بترفض وتقول السبب.
     */
    public function test(): void
    {
        $this->requireAuth();
        $this->requireRole(['owner', 'manager']);
        $this->requireCsrf();

        $res = PushNotify::sendNow('🔔 تجربة إشعار',
            'لو وصلك ده يبقى الإشعارات شغّالة تمام على الجهاز ده.',
            'all', 'push', (string) Auth::user());

        if ($res['total'] === 0) {
            $this->fail('مفيش ولا جهاز واحد مفعّل الإشعارات لسه — دوس «فعّل الإشعارات على الجهاز ده» الأول '
                . '(ولو على آيفون لازم تكون فاتح التطبيق المثبَّت مش المتصفح).', 'push');
        }
        if ($res['ok'] === 0) {
            $this->fail('اتبعت لـ' . $res['total'] . ' جهاز و**ما وصلش لحد**. سبب الرفض: '
                . implode(' · ', array_slice($res['errors'], 0, 3)), 'push');
        }
        $msg = 'وصل لـ' . $res['ok'] . ' جهاز من ' . $res['total'];
        if ($res['fail'] > 0) { $msg .= ' — وفشل ' . $res['fail'] . ' (' . implode(' · ', array_slice($res['errors'], 0, 2)) . ')'; }
        $this->done($msg . '.', 'push');
    }

    /**
     * الجلسة الحالية: مكتب ولا مندوب؟
     * @return array{0:string,1:?string,2:?int}
     */
    private function who(): array
    {
        if (Auth::check()) { return ['office', (string) Auth::user(), null]; }
        if (RepAuth::check()) { return ['rep', (string) ($_SESSION['rep_name'] ?? ''), (int) ($_SESSION['rep_id'] ?? 0)]; }
        http_response_code(401);
        $this->json(['ok' => false, 'msg' => 'لازم تسجّل دخول الأول.']);
        exit;
    }
}
