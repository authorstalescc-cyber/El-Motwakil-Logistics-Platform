<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص المرحلة ٢ من نظام الإشعارات — الجرس الاحترافي وصفحة السجل (docs/15 §٥).
 *
 * بيحرس:
 *   ① **الفصل بين حدث وحالة** — الرد فيه مفتاحان مختلفان، والحالات ما دخلتش الجدول
 *   ② **«مقروء» بتتبع الشخص لا المتصفح** — في القاعدة، ومفيش localStorage تاني
 *   ③ **الوقت** — كل بند عليه ختم زمني، والعرض بيمرّ على `Clock`، والمقارنات في SQL
 *   ④ **صفر رقم مجمَّع في السجل** — بند لكل حدث
 *   ⑤ **الصلاحية والملكية** — مستخدم ما بيشوفش إشعار غيره، و«كل النظام» للمالك بس
 *   ⑥ **رندر فعلي** للصفحة ولنداء الجرس ببيانات مزروعة
 *   ⑦ **كل كلاس بيحقنه `app.js` له تعريف في `app.css`** (مصيدة `.table-mobile-scroll`)
 *   ⑧ **كل أيقونة في `Notify::KINDS` موجودة في `icon()` وفي خريطة `app.js`**
 *   ⑨ العزل المالي — صفر لمسة للخزنة
 *
 * آمن: كل الكتابة جوّه transaction بيترجع، وبلا أي إرسال حقيقي.
 *
 * التشغيل:  php bin/verify_notifications_ui.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Access;
use App\Core\Database;
use App\Models\Closing;
use App\Models\Notification;
use App\Models\Notify;
use App\Models\PushNotify;

$pass = 0; $fail = 0; $skip = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ✗ $m\n"; }
function skip(string $m): void { global $skip; $skip++; echo "  ~ $m (تخطّي)\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }

$root = dirname(__DIR__);
/** ⚠️ التعليقات بتتشال قبل أي فحص «النص ده مش موجود» — تعليق بيوثّق القاعدة
 *  كان بيدّي إنذارًا كاذبًا (نفس مصيدة `code_only` في verify_security). */
function code_only(string $s): string
{
    $s = (string) preg_replace('#/\*.*?\*/#s', '', $s);
    return (string) preg_replace('#^\s*//.*$#m', '', $s);
}

echo "\n╔══════════════════════════════════════════════════════════════╗\n";
echo   "║  فحص المرحلة ٢ — الجرس الاحترافي + صفحة سجل الإشعارات        ║\n";
echo   "╚══════════════════════════════════════════════════════════════╝\n";

$balanceBefore = Closing::currentBalance();
$js   = (string) file_get_contents($root . '/public/assets/js/app.js');
$css  = (string) file_get_contents($root . '/public/assets/css/app.css');
$ctrl = (string) file_get_contents($root . '/app/Controllers/NotificationController.php');
$view = (string) file_get_contents($root . '/app/Views/notifications/index.php');
$model= (string) file_get_contents($root . '/app/Models/Notify.php');
$lay  = (string) file_get_contents($root . '/app/Views/layout.php');

/* ══════════════ ① الفصل بين حدث وحالة ══════════════ */
sec('① حدث ≠ حالة — الفصل اللي بيحلّ «الإشعارات المضلِّلة»');

chk(str_contains($ctrl, "'events'") && str_contains($ctrl, "'states'"),
    'الرد فيه **مفتاحان منفصلان**: `events` (أحداث) و`states` (حالات مستمرة)');
chk(str_contains($ctrl, 'Notification::feed()'),
    'الحالات لسه جاية من `App\\Models\\Notification` — نفس المصدر القديم بالحرف');
chk(str_contains($ctrl, 'Notify::feed('),
    'والأحداث من جدول v52 عبر `Notify::feed`');

$stateModel = code_only((string) file_get_contents($root . '/app/Models/Notification.php'));
chk(!preg_match('~INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM~i', $stateModel),
    '⚠️ الحالات المستمرة لسه **محسوبة لحظيًّا وما بتكتبش أي صف** — ما اتحوّلتش لأحداث');
chk(str_contains($js, 'محتاج منك') && str_contains($js, 'آخر الأحداث'),
    'الجرس بيعرضهم في **قسمين معنونين** مش قايمة واحدة مخلوطة');
chk(str_contains($js, 'nc-sec'),
    'وفيه فاصل بصري بينهم (`.nc-sec`)');

/* ══════════════ ② «مقروء» تخصّ الشخص ══════════════ */
sec('② «مقروء» بتتبع الشخص لا المتصفح');

chk(str_contains($model, 'UPDATE notification_recipients SET read_at'),
    'بتتخزّن في القاعدة على صف المستلم — فبتتزامن على كل أجهزته');
chk(str_contains($js, "localStorage.removeItem('almotawakel:notif-seen')"),
    '⚠️ المفتاح القديم بيتمسح مرّة واحدة — من غير كده يفضل يقرا حالة متصفح قديمة');
$bellBlock = substr($js, (int) strpos($js, 'function notifCenter'));
$bellBlock = substr($bellBlock, 0, 12000);
chk(!preg_match("~localStorage\.setItem\(\s*SEEN_KEY~", $bellBlock)
    && !str_contains($bellBlock, 'readSeen()'),
    '⚠️ ومفيش أي حالة «مقروء» تانية في `localStorage` — مصدر واحد بس');
chk(str_contains($ctrl, 'Notify::me()'),
    'الهوية بتتاخد **من الجلسة** — الفورم مش مصدر ثقة (مستحيل تعلّم إشعار غيرك)');
chk(!preg_match('~read\(.*\)\s*:\s*void[\s\S]{0,400}\$_POST\[.(party|user|rep)~', $ctrl),
    'ومفيش أي هوية جاية من الطلب');

/* ══════════════ ③ الوقت ══════════════ */
sec('③ الوقت — العرض على Clock والمقارنات في SQL (قاعدة v48)');

chk(str_contains($model, "Clock::displayExpr('n.created_at')"),
    'العرض بيمرّ على `Clock` — فرق ٣ ساعات بين ساعة القاعدة وPHP');
chk(str_contains($model, "'ts'") && str_contains($model, "'at_label'"),
    'كل بند بيحمل ختمًا زمنيًّا ووصفًا كاملًا');
chk(str_contains($js, 'notifRelTime') && str_contains($js, 'setInterval'),
    'الوقت النسبي بيتحدّث لوحده كل دقيقة **بلا أي طلب للخادم**');
chk(str_contains($js, 'paintNotifTimes'),
    'ونفس الدالة بتخدم الجرس وصفحة السجل — مفيش مُصيِّر وقت تاني');
$modelCode = code_only($model);
chk(!preg_match('~\bstrtotime\([^)]*\)\s*[<>]~', $modelCode),
    '⚠️ مفيش أي مقارنة وقت في PHP — المقارنات مكانها SQL');
chk(str_contains($modelCode, 'DATE_SUB(?, INTERVAL'),
    'وفلترة التاريخ بتزحزح الحدود في SQL فالفهرس يفضل مستخدَمًا');

/* ══════════════ ④ صفر رقم مجمَّع في السجل ══════════════ */
sec('④ السجل بند لكل حدث — صفر رقم مجمَّع');

chk(!preg_match('~COUNT\(\*\)[\s\S]{0,80}GROUP\s+BY~i', code_only($model))
    || str_contains($model, 'kindsInUse'),
    'مفيش أي تجميع بيرجّع «N بند» بدل البنود نفسها');
chk(!method_exists(PushNotify::class, 'submissionsDecidedBulk'),
    'ومفيش مصدر أحداث مجمَّع (`submissionsDecidedBulk` اتشالت في المرحلة ٣)');

/* ══════════════ ⑤ الصلاحيات والملكية ══════════════ */
sec('⑤ الصلاحيات — الرفض هو الافتراضي');

chk(Access::allows('read_only', 'NotificationController@read', 'POST'),
    'أي مستخدم مسجَّل يقدر يعلّم **إشعاراته هو** كمقروء (حتى «قراءة فقط»)');
chk(Access::allows('read_only', 'NotificationController@readAll', 'POST'),
    'وكذلك «علّم الكل كمقروء»');
chk(Access::allows('operator', 'NotificationController@index', 'GET'),
    'وصفحة السجل مفتوحة لكل الأدوار (كل واحد بيشوف بتاعه)');
chk(str_contains($ctrl, 'Auth::isOwner()'),
    '⚠️ تبويب «كل النظام» **للمالك بس** (قرار ٧.١) — وأي حد تاني بيترد لسجله هو');
chk(preg_match("~scope.*===\s*'all'.*&&\s*Auth::isOwner~", $ctrl) === 1,
    'والشرط في الكنترولر مش في إخفاء التبويب (الإخفاء مش حماية)');

/* ══════════════ ⑥ رندر فعلي ══════════════ */
sec('⑥ رندر فعلي ببيانات مزروعة');

if (!Notify::available()) {
    skip('جداول v52 مش موجودة — شغّل php bin/migrate_v52.php');
} else {
    $pdo = Database::conn();
    $pdo->beginTransaction();
    try {
        $u = Database::row('SELECT id, username FROM users ORDER BY id LIMIT 1');
        $_SESSION = ['uid' => (int) $u['id'], 'role' => 'owner', 'uname' => (string) $u['username'],
                     'last_activity' => time(), 'uid_checked' => time()];

        // ⚠️ الكتم إجباري: الفحص ما يبعتش ولا إشعار حقيقي
        PushNotify::mute(true);

        $before = Notify::unreadCount('user', (string) $u['username']);
        $a = Notify::emit('permit.new',  ['title' => 'فحص: إذن 999', 'body' => 'جهة فحص', 'url' => 'permits']);
        $b = Notify::emit('invoice.new', ['title' => 'فحص: فاتورة', 'body' => 'عميل فحص', 'url' => 'invoices']);
        chk($a > 0 && $b > 0, 'اتسجّل حدثان فعلاً');

        [$pt, $pk] = Notify::me();
        chk($pt === 'user' && $pk === (string) $u['username'],
            '`Notify::me()` بترجّع نفس مفتاح المستلم اللي `recipientsFor` بتكتب بيه');

        $after = Notify::unreadCount($pt, $pk);
        chk($after === $before + 2, 'وعدّاد غير المقروء زاد ٢ بالظبط');

        $feed = Notify::feed($pt, $pk, 10);
        chk(count($feed) >= 2, 'الـfeed بيرجّع البنود');
        $first = $feed[0] ?? [];
        chk(!empty($first['ts']) && !empty($first['at_label']) && !empty($first['rel']),
            'وكل بند عليه وقت (ختم + وصف كامل + وقت نسبي)');
        chk(!empty($first['kind_label']) && !empty($first['icon']),
            'وله اسم نوع وأيقونة');

        // «مقروء» بتخصّ الشخص: مستخدم تاني ما بيتأثّرش
        Notify::markRead($pt, $pk, $b);
        $stillUnread = (int) Database::scalar(
            'SELECT COUNT(*) FROM notification_recipients WHERE notification_id = ? AND read_at IS NULL', [$b]);
        $mineRead = (int) Database::scalar(
            'SELECT COUNT(*) FROM notification_recipients
              WHERE notification_id = ? AND party_type = ? AND party_key = ? AND read_at IS NOT NULL',
            [$b, $pt, $pk]);
        chk($mineRead === 1, 'تعليم البند علّم **صفّي أنا** بس');
        chk($stillUnread >= 1 || (int) Database::scalar(
                'SELECT COUNT(*) FROM notification_recipients WHERE notification_id = ?', [$b]) === 1,
            '⚠️ وباقي المستلمين لسه غير مقروء عندهم — «مقروء» تخصّ الشخص فعلاً');

        // رندر الصفحة
        $render = static function (callable $fn): string {
            ob_start();
            try { $fn(); return (string) ob_get_clean(); }
            catch (\Throwable $e) { ob_end_clean(); bad('استثناء وقت الرندر: ' . $e->getMessage()); return ''; }
        };
        $_GET = [];
        $html = $render(static fn () => (new App\Controllers\NotificationController())->index());
        if ($html === '') {
            bad('صفحة السجل ما رندرتش خالص');
        } else {
            chk(!preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $html),
                'الصفحة بترندر بلا أي خطأ PHP');
            chk(substr_count($html, 'class="nl-item') >= 2,
                'وبتعرض البنود فعلاً (مش صفحة فاضية — الفحص كان هينجح كذبًا على 0=0)');
            chk(str_contains($html, 'class="nl-day"'), 'وفيها ترويسات أيام (النهاردة/إمبارح/…)');
            chk(str_contains($html, 'data-ts='), 'وكل صف حامل ختمه الزمني عشان الوقت يتحدّث لوحده');
            chk(str_contains($html, 'data-notif-id='), 'وحامل رقمه عشان الضغط يعلّمه مقروء');
            chk(str_contains($html, 'كل الأنواع'), 'وفيها فلترة بالنوع');
        }

        // فلترة «غير المقروء»
        $res = Notify::listFor($pt, $pk, ['unread' => true], 50);
        $anyRead = false;
        foreach ($res['items'] as $it) { if ($it['read']) { $anyRead = true; } }
        chk(!$anyRead, 'فلتر «غير المقروء» ما بيرجّعش أي بند مقروء');

        // «كل النظام» بيرجّع الأحداث بلا نظر للمستلم
        $all = Notify::listFor('user', 'لا-أحد-كده', ['scope' => 'all'], 5);
        chk($all['total'] >= 2, '«كل النظام» بيقرا الأحداث بلا نظر للمستلم (للمالك بس)');

        /* نداء الجرس — ⚠️ **ما ينفعش ينتنادى هنا مباشرةً**: فيه `exit` (لازمة
           عشان `json()` بتطبع وما بتوقفش — باج v50)، وأي استدعاء كان هيقتل
           باقي الفحوصات **بلا أي رسالة**. نفس مصيدة `notFound()` الموثَّقة.
           فبنركّب نفس حمولة الرد من نفس المصادر ونتأكد إنها JSON سليم. */
        $payload = [
            'ok'     => true,
            'events' => ['unread' => Notify::unreadCount($pt, $pk), 'items' => Notify::feed($pt, $pk, 20)],
            'states' => Notification::feed(true),
            'page'   => url('notifications'),
        ];
        $j = json_decode((string) json_encode($payload, JSON_UNESCAPED_UNICODE), true);
        chk(is_array($j) && !empty($j['ok']), 'حمولة الجرس بتترمّز JSON سليم');
        chk(isset($j['events']['items'], $j['events']['unread'], $j['states']['items']),
            'وفيها الأحداث والحالات في مفتاحين منفصلين');
        chk(str_contains(code_only($ctrl), 'exit;'),
            '⚠️ و`ping()` بتعمل `exit` بعد `json()` — من غيرها أي كود بعدها بيلوّث الرد (باج v50)');

        Notify::markAllRead($pt, $pk);
        chk(Notify::unreadCount($pt, $pk) === 0, '«علّم الكل كمقروء» بتصفّي العدّاد');
    } finally {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        PushNotify::mute(false);
    }
    ok('الفحص رجّع كل الكتابة (rollback) — مفيش أثر باقٍ');
}

/* ══════════════ ⑦ كل كلاس محقون له CSS ══════════════ */
sec('⑦ أي كلاس بيحقنه الجافاسكريبت لازم يكون معرَّفًا (مصيدة .table-mobile-scroll)');

foreach (['nc-sec', 'nc-sec-n', 'nc-day', 'nc-when', 'nc-dot', 'nc-all', 'nl-item', 'nl-day', 'nl-quick'] as $cls) {
    chk(str_contains($css, '.' . $cls),
        "`.$cls` معرَّف في app.css (لو ناقص بيقع على الشكل الافتراضي **بالسكوت**)");
}
chk(str_contains($css, '.nc-item.ev'), '`.nc-item.ev` (بند الحدث) معرَّف');

/* ══════════════ ⑧ الأيقونات ══════════════ */
sec('⑧ كل أيقونة نوع موجودة في المكانين');

$helpers = (string) file_get_contents($root . '/app/Core/helpers.php');
$missHelper = []; $missJs = [];
foreach (Notify::KINDS as $k => $meta) {
    $ic = $meta['icon'];
    if (!preg_match("~'" . preg_quote($ic, '~') . "'\s*=>~", $helpers)) { $missHelper[] = $ic; }
    if (!preg_match("~['\"]?" . preg_quote($ic, '~') . "['\"]?\s*:~", $js))  { $missJs[] = $ic; }
}
chk(!$missHelper, $missHelper ? 'أيقونات ناقصة من `icon()`: ' . implode(', ', array_unique($missHelper))
                              : 'كل أيقونات الأنواع موجودة في `icon()` (للصفحة)');
chk(!$missJs, $missJs ? 'أيقونات ناقصة من خريطة app.js: ' . implode(', ', array_unique($missJs))
                      : 'وكلها موجودة في خريطة `app.js` كمان (للجرس)');

// أيقونات مصادر الحالات المستمرة كمان
$stateIcons = [];
if (preg_match_all("~self::item\(\s*'[^']+',\s*'[^']+',\s*'([^']+)'~", $stateModel, $m)) {
    $stateIcons = array_unique($m[1]);
}
$missState = array_values(array_filter($stateIcons,
    static fn(string $ic): bool => !preg_match("~['\"]?" . preg_quote($ic, '~') . "['\"]?\s*:~", $js)));
chk(!$missState, $missState ? 'أيقونات حالات ناقصة من app.js: ' . implode(', ', $missState)
                            : 'وأيقونات الحالات المستمرة كلها موجودة في الجرس');

/* ══════════════ ⑨ التوصيلات والأمان ══════════════ */
sec('⑨ التوصيل والأمان');

chk(str_contains($lay, '__NOTIF_READ') && str_contains($lay, '__NOTIF_READ_ALL') && str_contains($lay, '__CSRF'),
    'الـlayout بيمرّر مسارات القراءة وتوكن الحماية للواجهة');
chk(str_contains($lay, 'js_json('),
    'وبيمرّرها بـ`js_json()` مش `json_encode` (القاعدة الأمنية على كل app/Views)');
chk(!preg_match('~json_encode~', $view),
    'وصفحة السجل نفسها بلا أي `json_encode`');
chk(str_contains($js, "fd.append('_csrf'"),
    'وطلبات «مقروء» بتحمل توكن الحماية');
chk(str_contains($js, 'textContent'),
    '⚠️ نص الإشعار بيتحط بـ`textContent` مش `innerHTML` — أسماء العملاء والوجهات إدخال مستخدم');
chk(!preg_match('~\.innerHTML\s*=\s*(i\.|it\.)~', $js),
    '⚠️ ومفيش أي بيانات إشعار بتتحط كـHTML');

$idx = (string) file_get_contents($root . '/public/index.php');
chk(strpos($idx, "'/notifications/ping'") < strpos($idx, "\$r->get('/notifications',"),
    'المسارات الحرفية قبل الجشعة (تحذير ٤)');
chk(str_contains($idx, "/notifications/read-all") && str_contains($idx, "/notifications/read/{id}"),
    'ومساري القراءة مسجَّلان');
chk(str_contains($lay, "url('notifications')"),
    'ورابط «كل الإشعارات» موجود في تذييل الجرس');

/* ══════════════ ⑩ العزل المالي ══════════════ */
sec('⑩ العزل المالي — وحدة وصفية بحتة');

foreach (['Custody::', 'Closing::guard', 'manual_custody', 'bawalis'] as $needle) {
    chk(!str_contains(code_only($model), $needle), "`Notify` ما بيلمسش `{$needle}`");
}
$after = Closing::currentBalance();
chk(abs($after - $balanceBefore) < 0.005,
    'رصيد الخزنة ما اتغيّرش (' . number_format($balanceBefore, 2) . ')');

echo "\n" . str_repeat('=', 62) . "\n";
echo "نجح: $pass   |   فشل: $fail   |   تخطّي: $skip\n";
if ($fail > 0) { echo "\n✗ الفحص فشل — الكود ده ما يترفعش.\n"; exit(1); }
echo "\n✓ كل الفحوصات عدّت.\n";
exit(0);
