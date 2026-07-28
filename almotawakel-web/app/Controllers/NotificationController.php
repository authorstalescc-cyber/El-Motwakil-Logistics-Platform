<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\RepAuth;
use App\Models\Notification;
use App\Models\Notify;
use App\Models\NotifyPref;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * مركز الإشعارات — الجرس وصفحة السجل والتفضيلات (المراحل ٢ · ٤ · ٦ من docs/15).
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ **حاجتان مختلفتان في رد واحد — وده جوهر الوحدة:**
 *   • `events` = **أحداث** وقعت وانتهت، جاية من جدول `notifications` (v52).
 *     كل واحد بند مستقل بوقته وصاحبه، و«مقروء» بتاعته **متخزَّنة في القاعدة**
 *     فبتتزامن على كل أجهزة الشخص.
 *   • `states` = **حالات مستمرة** (٣ أيام مش متقفلة، أرقام سرية ضعيفة) جاية
 *     من `App\Models\Notification` وبتفضل **محسوبة لحظيًّا وما بتدخلش أي جدول**.
 *
 *   الخلط بينهم هو أصل مشكلة «الإشعارات المضلِّلة» — فالواجهة بتعرضهم في
 *   قسمين مفصولين بصريًّا، والحالات **ما بتدخلش السجل ولا بترنّ**.
 *
 * ⚠️ **الكنترولر ده بيخدم جلسة المكتب وجلسة المندوب مع بعض** (المرحلة ٦ —
 *    جرس المندوب). عشان كده الحارس هو `requireAnySession()` مش `requireAuth()`:
 *    جلسة المندوب **ما فيهاش `uid`** (v35) فـ`Auth::check()` بترجّع false لها،
 *    و`Access::enforce` بتعدّيها بلا فحص (موثَّق) — فالحارس هنا هو الحد الحقيقي.
 *    و`Notify::me()` بترجّع هوية الطرفين بنفس مفاتيح `recipientsFor`، فمستحيل
 *    حد يقرا أو يعلّم إشعار غيره.
 *
 * ⚠️ **الأداء زي ما هو:** الجرس بيترسم فاضي من الخادم (صفر تكلفة على رندر أي
 *    صفحة)، والواجهة بتنادي `/notifications/ping` بعد التحميل وكل ٦٠ ثانية،
 *    والـpolling بيقف لما التبويب يتخفي.
 *
 * ⚠️ **الوقت:** العرض بيمرّ على `App\Core\Clock` (جوّه `Notify::shape`)، وأي
 *    مقارنة زمنية بتفضل **جوّه SQL** — فرق ٣ ساعات بين ساعة القاعدة وPHP
 *    موثَّق في v48.
 */
class NotificationController extends Controller
{
    /** صفوف صفحة السجل. */
    private const PER_PAGE = 40;

    /** أقصى عدد أحداث في لوحة الجرس — الباقي في الصفحة. */
    private const BELL_LIMIT = 20;

    /** مُدد التأجيل المسموحة بالدقايق — **قائمة بيضا**، الفورم مش مصدر ثقة. */
    private const SNOOZE_CHOICES = [60, 180, 480, 1440];

    /**
     * حارس بيقبل جلسة المكتب **أو** جلسة المندوب.
     *
     * ⚠️ **ده الحد الأمني الحقيقي للمسارات دي.** `Access::enforce` بتعدّي جلسة
     *    المندوب بلا فحص لأنها مالهاش `uid` — فلو الحارس ده اتشال، المسارات
     *    تبقى مفتوحة لأي زائر.
     */
    private function requireAnySession(): array
    {
        [$pt, $pk] = Notify::me();
        if ($pt === '' || $pk === '') {
            if ($this->wantsJson()) {
                http_response_code(401);
                $this->json(['ok' => false, 'error' => 'الجلسة انتهت — سجّل دخول تاني.']);
                exit;
            }
            redirect(RepAuth::check() ? 'm' : 'login');
        }
        return [$pt, $pk];
    }

    /** نداء واحد بيغذّي الجرس **وشارات القائمة الجانبية** مع بعض. */
    public function ping(): void
    {
        [$pt, $pk] = $this->requireAnySession();
        header('Cache-Control: private, no-store, max-age=0');
        $this->json([
            'ok'     => true,
            // الأحداث — بند لكل حاجة حصلت، بوقتها
            'events' => [
                'unread'  => Notify::unreadCount($pt, $pk),
                'items'   => Notify::feed($pt, $pk, self::BELL_LIMIT),
                'snoozed' => Notify::snoozedCount($pt, $pk),
            ],
            // الحالات المستمرة — نفس المصدر القديم بالحرف (شارات القائمة بتقرا منه).
            // ⚠️ للمكتب بس: `Notification::feed` بتقرا `Auth::role()` وبتفلتر
            //    بـ`Access`، وجلسة المندوب مالهاش دور مكتب أصلاً.
            'states' => $pt === 'user' ? Notification::feed() : ['count' => 0, 'items' => [], 'at' => ''],
            'page'   => url($pt === 'rep' ? 'm/notifications' : 'notifications'),
            'snooze' => self::SNOOZE_CHOICES,
            // بصمة النسخة — بتغذّي التحديث الإجباري **بلا أي طلب زيادة**
            'build'  => \App\Core\App::buildId(),
        ]);
        exit;   // ⚠️ `json()` بتطبع وما بتوقفش — أي كود بعدها بيلوّث الرد (باج v50)
    }

    /**
     * بصمة النسخة لوحدها — للشاشات اللي مالهاش نداء ping يغذّيها.
     *
     * ⚠️ **بلا حارس جلسة عن قصد** (زي `/t/{token}` بالظبط): الرد رقم واحد
     *    مشتقّ من تاريخ آخر تعديل على ملفات التصميم/الجافاسكريبت — مفيهوش ولا
     *    بيانات مستخدم ولا مبلغ ولا اسم. ولو اتحطّ عليه حارس، **شاشة الدخول**
     *    (وهي كمان بتحمّل وحدة التحديث) كانت هتفضل تاخد 401 كل دقيقة بلا فايدة.
     *
     * ⚠️ **ممنوع تزوّد أي مفتاح تاني في الرد ده.** أول ما يرجّع أي حاجة تخصّ
     *    النظام أو المستخدم، بيتحوّل من ختم عام لتسريب — وساعتها يبقى محتاج
     *    حارس، والحارس بيكسر شاشة الدخول. سيبه ختم وبس.
     *
     * مفيش أي لمسة للقاعدة — `App::buildId()` بتقرا تواريخ ملفات وخلاص.
     */
    public function build(): void
    {
        header('Cache-Control: private, no-store, max-age=0');
        $this->json(['build' => \App\Core\App::buildId()]);
        exit;   // ⚠️ نفس قاعدة v50: `json()` بتطبع وما بتوقفش
    }

    /** صفحة السجل الكامل (المكتب). */
    public function index(): void
    {
        $this->requireAuth();
        [$pt, $pk] = Notify::me();

        // «كل النظام» للمالك بس (قرار ٧.١) — وأي حد تاني بيترد لسجله هو
        $scope = (($_GET['scope'] ?? '') === 'all' && Auth::isOwner()) ? 'all' : 'mine';
        $page  = max(1, (int) ($_GET['page'] ?? 1));
        $f = [
            'scope'  => $scope,
            'kind'   => (string) ($_GET['kind'] ?? ''),
            'q'      => trim((string) ($_GET['q'] ?? '')),
            'from'   => self::validDate((string) ($_GET['from'] ?? '')) ? $_GET['from'] : '',
            'to'     => self::validDate((string) ($_GET['to'] ?? '')) ? $_GET['to'] : '',
            'unread' => ($_GET['unread'] ?? '') === '1',
        ];

        $res   = Notify::listFor($pt, $pk, $f, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $pages = max(1, (int) ceil($res['total'] / self::PER_PAGE));

        $this->render('notifications/index', [
            'title'   => 'الإشعارات',
            'items'   => $res['items'],
            'total'   => $res['total'],
            'page'    => min($page, $pages),
            'pages'   => $pages,
            'filters' => $f,
            'scope'   => $scope,
            'unread'  => Notify::unreadCount($pt, $pk),
            'snoozed' => Notify::snoozedCount($pt, $pk),
            'kinds'   => Notify::kindsInUse(),
            'canAll'  => Auth::isOwner(),
            'ready'   => Notify::available(),
            'snoozeChoices' => self::SNOOZE_CHOICES,
            'waPhone' => wa_phone((string) \App\Core\App::setting('company_phone', '')),
        ]);
    }

    /* ═══════════════════════════════════════════ التفضيلات (المرحلة ٤) */

    /**
     * شاشة «إعدادات الإشعارات» — نوع × قناة.
     *
     * ⚠️ **بتخدم المكتب والمندوب** بنفس الكود؛ المندوب بيشوف **أنواعه هو بس**
     *    (اللي `Notify::ROUTES` بتوجّهها له)، فمش هيلاقي قدامه أنواع مالهاش
     *    معنى عنده زي «تقفيل يوم».
     */
    public function prefs(): void
    {
        [$pt, $pk] = $this->requireAnySession();
        $kinds = self::kindsFor($pt);

        $data = [
            'title'  => 'إعدادات الإشعارات',
            'rows'   => NotifyPref::matrix($pt, $pk, $kinds),
            'ready'  => NotifyPref::available(),
            'party'  => $pt,
            'isRep'  => $pt === 'rep',
        ];
        // بوابة المندوب شاشاتها **برّه layout المكتب** (v35)
        $pt === 'rep'
            ? $this->render('m/notif_prefs', $data, false)
            : $this->render('notifications/prefs', $data);
    }

    public function prefsSave(): void
    {
        [$pt, $pk] = $this->requireAnySession();
        $this->requireCsrf();
        $kinds = self::kindsFor($pt);
        $back  = $pt === 'rep' ? 'm/notifications/prefs' : 'notifications/prefs';

        if (($_POST['_reset'] ?? '') === '1') {
            NotifyPref::reset($pt, $pk);
            $this->done('رجعت كل الإشعارات للوضع الافتراضي — هتوصلك كلها تاني.', $back);
        }

        // ⚠️ `$kinds` هي القائمة البيضا — الفورم مش مصدر ثقة، وأي نوع مش فيها
        //    بيتجاهل تمامًا (ومندوب ما يقدرش يعدّل تفضيل نوع مش بتاعه).
        $wanted = [];
        foreach ($kinds as $k) {
            $wanted[$k] = [
                'in_app' => isset($_POST['in_app'][$k]),
                'push'   => isset($_POST['push'][$k]),
            ];
        }
        $changed = NotifyPref::save($pt, $pk, $wanted, $kinds);

        $this->done(
            $changed > 0
                ? "اتحفظ ✓ — فيه {$changed} نوع مقفول أو محدود دلوقتي."
                : 'اتحفظ ✓ — كل الأنواع شغّالة على القناتين.',
            $back
        );
    }

    /** أنواع الإشعارات اللي بتوصل الطرف ده فعلاً — من `Notify::ROUTES` نفسها. */
    private static function kindsFor(string $partyType): array
    {
        $out = [];
        foreach (Notify::kindList() as $kind) {
            $aud = Notify::ROUTES[$kind][0] ?? 'office';
            $forRep    = in_array($aud, ['rep', 'both', 'all'], true);
            $forOffice = in_array($aud, ['office', 'both', 'all'], true);
            if (($partyType === 'rep' && $forRep) || ($partyType === 'user' && $forOffice)) {
                $out[] = $kind;
            }
        }
        return $out;
    }

    /* ═══════════════════════════════════════════ إجراءات البند */

    /**
     * تعليم بند كمقروء.
     * ⚠️ بتاخد الهوية **من الجلسة** لا من الفورم، فمستحيل حد يعلّم إشعار غيره.
     */
    public function read(string $id): void
    {
        [$pt, $pk] = $this->requireAnySession();
        $this->requireCsrf();
        Notify::markRead($pt, $pk, (int) $id);
        if ($this->wantsJson()) {
            $this->json(['ok' => true, 'unread' => Notify::unreadCount($pt, $pk)]);
            exit;
        }
        redirect(safe_back('notifications', $_POST['_back'] ?? null));
    }

    public function readAll(): void
    {
        [$pt, $pk] = $this->requireAnySession();
        $this->requireCsrf();
        $n = Notify::markAllRead($pt, $pk);
        if ($this->wantsJson()) {
            $this->json(['ok' => true, 'marked' => $n, 'unread' => 0]);
            exit;
        }
        $this->done($n > 0 ? "اتعلّم {$n} إشعار كمقروء." : 'مفيش إشعارات غير مقروءة.', 'notifications');
    }

    /**
     * تأجيل بند — «فكّرني بعدين» (المرحلة ٦).
     *
     * ⚠️ **المدّة من قائمة بيضا** — الفورم بيبعت رقمًا وإحنا بنرفض أي حاجة
     *    برّه `SNOOZE_CHOICES`، فما ينفعش حد يأجّل بند ١٠ سنين ويخفيه للأبد.
     */
    public function snooze(string $id): void
    {
        [$pt, $pk] = $this->requireAnySession();
        $this->requireCsrf();
        $mins = (int) ($_POST['minutes'] ?? 0);
        if (!in_array($mins, self::SNOOZE_CHOICES, true)) { $mins = self::SNOOZE_CHOICES[0]; }

        $ok = Notify::snooze($pt, $pk, (int) $id, $mins);
        if ($this->wantsJson()) {
            $this->json(['ok' => $ok, 'unread' => Notify::unreadCount($pt, $pk)]);
            exit;
        }
        $ok
            ? $this->done('اتأجّل — هيرجع يظهر بعد ' . self::snoozeLabel($mins) . '.', 'notifications')
            : $this->fail('مقدرناش نأجّل البند ده.', 'notifications');
    }

    /** وصف عربي لمدة التأجيل — **مصدر واحد** للجرس والصفحة والرسالة. */
    public static function snoozeLabel(int $minutes): string
    {
        return match (true) {
            $minutes >= 1440 => 'يوم',
            $minutes >= 480  => intf(intdiv($minutes, 60)) . ' ساعات',
            $minutes >= 60   => intf(intdiv($minutes, 60)) . ' ساعة',
            default          => intf($minutes) . ' دقيقة',
        };
    }

    private static function validDate(string $v): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
    }
}
