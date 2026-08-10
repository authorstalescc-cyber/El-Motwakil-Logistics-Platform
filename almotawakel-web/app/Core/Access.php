<?php
namespace App\Core;

/**
 * سياسة صلاحيات المكتب المركزية.
 *
 * الراوتر يطبقها قبل استدعاء الكنترولر. الحراس داخل الكنترولرات الحساسة
 * يظلون دفاعًا إضافيًا، وإخفاء الروابط في الواجهة ليس وسيلة حماية.
 */
class Access
{
    public const ROLES = [
        'owner'      => 'مالك',
        'manager'    => 'مدير',
        'accountant' => 'محاسب',
        'operator'   => 'تشغيل',
        'read_only'  => 'قراءة فقط',
    ];

    private const COMMON_POST = [
        'AuthController@logout',
        'WorkDateController@save',
        'AssistantController@ask',
        'PermitController@invoicePreview',
        // تفعيل/إقفال إشعارات الجهاز — حق لأي مستخدم مسجَّل مهما كان دوره،
        // وبيخصّ جهازه هو بس (الكنترولر بياخد الهوية من الجلسة لا من الفورم).
        'PushController@subscribe',
        'PushController@unsubscribe',
        // تعليم إشعارات **نفسه** كمقروء — حق لأي مستخدم مسجَّل مهما كان دوره،
        // والكنترولر بياخد الهوية من الجلسة لا من الفورم فمستحيل يعلّم لغيره.
        'NotificationController@read',
        'NotificationController@readAll',
        // تأجيل بند وتفضيلات الإشعارات — كلها بتخصّ **إشعارات نفسه** (v53).
        'NotificationController@snooze',
        'NotificationController@prefsSave',
        /* تفضيلات العرض (v75) — **حق أي مستخدم مسجَّل مهما كان دوره**: إخفاء
           عمود أو تضييق الصفوف مالوش أي أثر على بيانات ولا صلاحية، والكنترولر
           بياخد الهوية من الجلسة لا من الفورم فمستحيل يكتب لغيره. من غير
           السطور دي `read_only` كان هيتمنع من إخفاء عمود في شاشة بيقراها. */
        'PrefController@save',
        'PrefController@adopt',
        'PrefController@reset',
    ];

    /**
     * إرسال الإشعارات وشاشتها — **للمالك والمدير بس**.
     * ⚠️ لازم تفضل هنا: الفحص ده بيجي **قبل** السماح العام لطلبات GET تحت،
     *    فده المكان الوحيد اللي بيمنع باقي الأدوار من فتح `/push` وإرسال
     *    إشعار لكل الشركة. `requireRole` في الكنترولر طبقة تانية.
     */
    private const OWNER_MANAGER_ONLY = [
        // تنظيف البيانات: الدمج بيعدّل مئات الصفوف دفعة واحدة — مش إجراء يومي.
        // ⚠️ لازم تفضل هنا — القايمة دي بتتفحص **قبل** السماح العام لطلبات GET.
        'QualityController@*',
        // مراجعة/رفع/اعتماد ملف Excel تكشف وتعدّل بيانات مالية مجمّعة؛
        // المجموعة كلها مقصورة على المالك والمدير.
        'ExcelSyncController@*',
        // الحذف النهائي لبند مندوب: بيشيل السجل من الصندوق خالص (بينزل السلة).
        // ⚠️ مقصود إنه أضيق من باقي إجراءات الصندوق (الاعتماد/الرفض متاحين
        //    للمحاسب): اللي يقدر **يسترجع** من `/audit` هو بس اللي يقدر يحذف.
        'RepCustodyController@deleteSubmission',
        'RepCustodyController@deleteMany',
        'PushController@index',
        'PushController@send',
        'PushController@test',
    ];

    private const OWNER_ONLY = [
        // لوحة المراقبة الأمنية: بتعرض مين بيحاول يدخل على أنهي حساب وإمتى.
        // ⚠️ لازم تفضل هنا — القايمة دي بتتفحص **قبل** السماح العام لطلبات GET
        //    تحت، فده المكان الوحيد اللي بيمنع باقي الأدوار من قراءتها.
        'SecurityController@*',
        'BackupController@*',
        'SettingsController@addUser',
        'SettingsController@updateUser',
        'SettingsController@deleteUser',
        'SettingsController@saveAi',
        'SettingsController@testAi',
        // شكل الشركة وتثبيته — قرار مالك (الحارس التاني في `UserPref::setCompany`)
        'SettingsController@prefLock',
        'SettingsController@prefCompanyDelete',
        // إطفاء وحدة أو تغيير القائمة — قرار مالك (الحارس التاني في `NavModule::save`)
        'SettingsController@saveModules',
    ];

    /** وحدات الكتابة المالية المسموحة للمحاسب (والمدير والمالك). */
    private const FINANCIAL_CONTROLLERS = [
        'BawalisController', 'PaymentController', 'SettlementController',
        'DebtController', 'CustodyController', 'OfficeController',
        'CompanySafeController', 'OfficeCarController', 'OfficeDriverController',
        'DailyClosingController', 'OwnerStatementController',
        'InvoiceController', 'ExpenseController', 'RepController',
        'RepCustodyController', 'StatementsController',
    ];

    /** وحدات الكتابة التشغيلية المسموحة لدور التشغيل. */
    private const OPERATIONAL_CONTROLLERS = [
        'BawalisController', 'TakhlisController', 'GeneratorsController',
        'TrackController', 'DashboardController',
    ];

    /** إجراءات الإذن المالية التي لا ينفذها دور التشغيل. */
    private const PERMIT_FINANCIAL_ACTIONS = [
        'invoiceStore', 'invoiceDelete', 'createBill', 'updateBill',
        'deleteBill', 'copyBill',
    ];

    public static function normalizeRole(?string $role): string
    {
        return isset(self::ROLES[$role ?? '']) ? (string) $role : 'read_only';
    }

    public static function label(?string $role): string
    {
        $role = self::normalizeRole($role);
        return self::ROLES[$role];
    }

    /** يطبق السياسة على handler بصيغة Controller@action. */
    public static function enforce(string $handler, string $httpMethod): void
    {
        // الجلسات العامة/بوابة المندوب يطبق كنترولرها حارسها الخاص.
        if (!Auth::check()) { return; }
        if (self::allows(Auth::role(), $handler, strtoupper($httpMethod))) { return; }

        self::deny($handler, strtoupper($httpMethod));
    }

    public static function allows(string $role, string $handler, string $httpMethod): bool
    {
        $role = self::normalizeRole($role);
        [$controller, $action] = array_pad(explode('@', $handler, 2), 2, '');
        $method = strtoupper($httpMethod);

        /* ⚠️⚠️ **الوحدة المطفية بترفض قبل أي سماح — حتى للمالك** (v77).
           الإطفاء قرار المالك نفسه، فتخطّيه له كان هيخلّيه بلا معنى: الوحدة
           «مطفية» والمالك لسه شايفها شغّالة. والرجوع بضغطة من `/settings/modules`.
           ⚠️ و`NavModule::LOCKED` بيمنع إطفاء الإعدادات ولوحة التحكّم أصلاً،
              فمستحيل الحارس ده يقفل طريق الرجوع. */
        /* ⚠️⚠️ **`class_exists` + `try` مش زيادة دفاع — ضرورة**: `Access` كلاس
           في `Core` بيتحمَّل في سياقات **بلا autoloader ولا قاعدة** (الفاحص
           البنيوي `verify_access_matrix` بيشغّله كده عن قصد). الاستدعاء المباشر
           كان بيرمي `Class not found` **ويقتل الفاحص كله**.
           والسقوط هنا **مفتوح عن قصد**: إطفاء الوحدة تنظيم قائمة مش حاجز أمني
           — الحاجز هو الدور، وهو فوق وتحت. لو القاعدة مش متاحة، الأسوأ إن
           وحدة مطفية تفتح؛ ولو كان بيقفل، عطل قاعدة كان هيقفل النظام كله. */
        if (class_exists(\App\Models\NavModule::class)) {
            try {
                $slug = \App\Models\NavModule::slugForHandler($handler);
                if ($slug !== null && \App\Models\NavModule::isDisabled($slug)) { return false; }
            } catch (\Throwable $e) { /* بلا قاعدة → مفيش إطفاء */ }
        }

        if ($role === 'owner') { return true; }
        if (self::matchesAny($handler, self::OWNER_ONLY)) { return false; }
        if (self::matchesAny($handler, self::OWNER_MANAGER_ONLY)) { return $role === 'manager'; }

        // الإعدادات غير السرية للمدير فقط، ولا تظهر لباقي الأدوار.
        if ($controller === 'SettingsController') {
            return $role === 'manager';
        }

        // كل الأدوار المسجلة تستطيع القراءة، عدا الصفحات الحساسة المحجوبة أعلاه.
        if ($method === 'GET' || $method === 'HEAD') { return true; }
        if (in_array($handler, self::COMMON_POST, true)) { return true; }

        if ($role === 'manager') { return true; }
        if ($role === 'read_only') { return false; }

        if ($role === 'accountant') {
            if (in_array($controller, self::FINANCIAL_CONTROLLERS, true)) { return true; }
            if ($controller === 'PermitController' && in_array($action, self::PERMIT_FINANCIAL_ACTIONS, true)) { return true; }
            if ($controller === 'DirectoryController' && in_array($action, ['addPayment', 'deletePayment'], true)) { return true; }
            return false;
        }

        if ($role === 'operator') {
            if (in_array($controller, self::OPERATIONAL_CONTROLLERS, true)) { return true; }
            if ($controller === 'PermitController') {
                return !in_array($action, self::PERMIT_FINANCIAL_ACTIONS, true);
            }
        }

        return false;
    }

    public static function canOpenSettings(): bool
    {
        return Auth::check() && in_array(Auth::role(), ['owner', 'manager'], true);
    }

    public static function canManageSecrets(): bool
    {
        return Auth::check() && Auth::role() === 'owner';
    }

    private static function matchesAny(string $handler, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_ends_with($pattern, '@*') && str_starts_with($handler, substr($pattern, 0, -1))) {
                return true;
            }
            if ($handler === $pattern) { return true; }
        }
        return false;
    }

    /**
     * الرفض بيعرض **صفحة 403 حقيقية** (المرحلة ٢).
     *
     * قبل كده كان `flash('error') + redirect('')` — يعني المستخدم بيتقذف على
     * الداشبورد ويفقد اللي كان بيعمله من غير ما يعرف أنهي إجراء اترفض ولا
     * مين يقدر يعمله. الصفحة الجديدة بتقول التلاتة وبتديله «ارجع للي كنت فيه».
     *
     * رد الـAJAX بياخد المفتاحين `error` و`flash` مع بعض: الواجهة
     * (`ajaxSubmit` في app.js) بتقرا `data.flash`، وكان بيوصلها المفتاح
     * `error` بس فتعرض رسالة عامة بدل السبب الحقيقي.
     */
    private static function deny(string $handler = '', string $method = 'POST'): void
    {
        $msg = 'ليست لديك صلاحية لتنفيذ هذا الإجراء.';
        http_response_code(403);
        $json = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
            || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(
                ['ok' => false, 'error' => $msg, 'flash' => $msg, 'errors' => ['_form' => $msg]],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }
        (new Controller())->render('errors/403', [
            'title'         => 'غير مسموح',
            'deniedHandler' => $handler,
            'deniedMethod'  => $method,
        ]);
        exit;
    }
}
