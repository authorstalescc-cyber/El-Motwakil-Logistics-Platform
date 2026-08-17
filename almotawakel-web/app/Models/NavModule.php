<?php
namespace App\Models;

use App\Core\Auth;
use App\Core\Access;
use App\Core\Database;

/**
 * **وحدات النظام والقائمة الجانبية — من القاعدة مش من الكود** (docs/19 · المرحلة ٤ · v77).
 *
 * ═══ اللزمة ═══
 * القائمة كانت **مصفوفة PHP مكتوبة بالإيد في `layout.php`**: ترتيب الوحدات
 * وأسماؤها وأيقوناتها ما تتغيّرش إلا بتعديل كود، و**مفيش أي طريقة تطفي وحدة**
 * مش مستعملة — لو الشركة بطّلت «المولدات» تفضل في القائمة للأبد.
 *
 * ═══ الافتراضي في الكود · التعديل في القاعدة ═══
 * `DEFAULTS` تحت هي **المصدر الأصلي** (نفس المصفوفة القديمة بالحرف)، وجدول
 * `module_settings` بيحمل **الفروق وبس**. يعني قاعدة فاضية = النظام زي ما هو
 * بالظبط، ووحدة جديدة في الكود بتظهر لوحدها بلا أي صف.
 *
 * ⚠️⚠️ **الإطفاء بيتفرض في `Access` مش في إخفاء اللينك** (قاعدة ③): الوحدة
 *    المطفية بترد ٤٠٤ مؤدَّبة حتى لو المستخدم كتب المسار بإيده.
 * ⚠️ **الإطفاء ما بيمسحش أي بيانات** — بيخفي الوحدة وبس، والرجوع بضغطة.
 * ⚠️ **`settings` و`` (لوحة التحكم) ما ينفعش يتطفوا** — إطفاؤهم بيقفل المالك
 *    برّه النظام بلا طريق رجوع غير قاعدة البيانات.
 */
class NavModule
{
    /** وحدات ما ينفعش تتطفي أبدًا — طريق الرجوع الوحيد. */
    public const LOCKED = ['', 'settings'];

    /** الافتراضي — **نفس مصفوفة `layout.php` القديمة بالحرف**.
     *  كل بند: [المسار, الاسم, الأيقونة, معالِج الصلاحية, مفتاح الشارة] */
    public const DEFAULTS = [
    ['__sec', 'الرئيسية', null, null, null],
    ['',            'لوحة التحكم',   'dashboard',     'DashboardController@index',   null],
    ['assistant',   'المساعد الذكي', 'sparkles',      'AssistantController@index',   null],

    ['__sec', 'الشغل اليومي', null, null, null],
    ['permits',     'الأذونات والشهادات', 'folder',   'PermitController@index',      null],
    ['bawalis',     'البوالص',       'truck',         'CrudController@index',        null],
    ['cars',        'سيارات المكتب', 'truck',         'OfficeCarController@index',   null],
    ['tracking',    'تتبع الشحنات',  'map-pin',       'TrackController@index',       'track_stale'],
    ['closing',     'التقفيل اليومي','calculator',    'DailyClosingController@index','closing_open'],

    ['__sec', 'العملاء', null, null, null],
    /* ⚠️ «حسابات العملاء» أول القسم عن قصد (v93): هي **نقطة الدخول** لأي شغل
       على عميل — الفواتير والتحصيلات تحتها سجلات عرضية عبر كل العملاء. */
    ['clients',     'حسابات العملاء','users',         'ClientController@index',      null],
    ['invoices',    'فواتير العملاء','receipt',       'InvoiceController@index',     'ready_invoice'],
    ['payments',    'تحصيلات العملاء','wallet',       'PaymentController@index',     null],
    ['debts',       'المديونيات',    'credit-card',   'DebtController@index',        null],

    ['__sec', 'أصحاب البيان', null, null, null],
    ['statements',  'كشوف الحساب',  'file-text',     'OwnerStatementController@index', null],
    ['settlements', 'السداد',        'banknote',      'SettlementController@index',  null],

    ['__sec', 'الخزنة والمصروفات', null, null, null],
    // ⚠️ خزنة الشركة قبل خزنة المكتب عن قصد — هي الأكبر واللي بتغذّيها (v66).
    ['company-safe', 'خزنة الشركة',  'home',          'CompanySafeController@index', null],
    ['custody',     'خزنة المكتب',   'briefcase',     'CustodyController@index',     null],
    ['office',      'كروت الفيزا',   'credit-card',   'OfficeController@index',      null],
    ['expenses',    'المصروفات',     'trending-down', 'ExpenseController@index',     'receipts_missing'],

    ['__sec', 'المناديب', null, null, null],
    ['rep-custody', 'عهدة المناديب', 'briefcase',     'RepCustodyController@index',  'rep_pending'],
    /* ⚠️ التسمية اتغيّرت في v87: بقى فيه شاشتان — `payroll` بتحسب الفترة
       وبتعتمدها، و`reps` كارت وكشف حساب كل مندوب. الاسم القديم («رواتب
       وحسابات المناديب») كان بيوعد بحاجة الشاشة ما بتعملهاش. */
    ['payroll',     'رواتب المناديب', 'banknote',    'PayrollController@index',     null],
    ['reps',        'حسابات المناديب', 'user',       'RepController@index',         null],

    ['__sec', 'السجلات والإدارة', null, null, null],
    ['directory',   'دليل الأطراف',  'users',         'DirectoryController@index',   null],
    ['legacy',      'أرشيف البوالص القديمة', 'inbox', 'LegacyController@index',      null],
    ['takhlis',     'التخليص',       'clipboard',     'TakhlisController@index',     null],
    ['generators',  'المولدات',      'cpu',           'GeneratorsController@index',  null],
    ['ops',         'تقارير التشغيل','package',       'OpsReportController@index',   null],
    ['reports',     'التقارير',      'bar-chart',     'ReportsController@index',     null],
    ['audit',       'سجل العمليات',  'clipboard',     'AuditController@index',       null],
    ['push',        'إشعارات التطبيق','bell',         'PushController@index',        null],
    ['quality',     'تنظيف البيانات','clipboard',     'QualityController@index',     'data_quality'],
    ['receipt-model','موديل الإيصالات','cpu',         'ReceiptModelController@index', null],
    ['security',    'المراقبة الأمنية','alert',       'SecurityController@index',    'failed_logins'],
    ['settings',    'الإعدادات',     'settings',      'SettingsController@index',    'weak_pins'],

    ];

    private static ?bool $ready = null;
    private static ?array $rows = null;

    public static function ready(): bool
    {
        if (self::$ready !== null) { return self::$ready; }
        try {
            self::$ready = (bool) Database::scalar(
                'SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = ?', ['module_settings']);
        } catch (\Throwable $e) { self::$ready = false; }
        return self::$ready;
    }

    /** ⚠️ إلزامية في آخر أي ترحيل بينشئ الجدول (مصيدة الكاش الساكن v57). */
    public static function refreshReady(): bool
    {
        self::$ready = null; self::$rows = null;
        return self::ready();
    }

    /** فروق القاعدة: slug => صف. */
    private static function overrides(): array
    {
        if (self::$rows !== null) { return self::$rows; }
        if (!self::ready()) { return self::$rows = []; }
        $out = [];
        foreach (Database::all('SELECT * FROM module_settings') as $r) {
            $out[(string) $r['slug']] = $r;
        }
        return self::$rows = $out;
    }

    /**
     * **القائمة النهائية** بعد دمج الافتراضي بالفروق وفلترة الدور.
     *
     * ⚠️ الفلترة بالدور بتفضل زي ما كانت: **الرفض هو الافتراضي** — البند بيظهر
     *    بس لو `Access::allows` بتسمح لدور المستخدم يفتح صفحته.
     */
    public static function menu(): array
    {
        $ov   = self::overrides();
        $role = Auth::role();

        $items = [];
        foreach (self::DEFAULTS as $i => $item) {
            [$seg, $label, $ic, $handler, $badge] = $item;
            if ($ic === null) { $items[] = ['__sec', $label, null, null, null, $i]; continue; }
            if ($handler !== null && !Access::allows($role, $handler, 'GET')) { continue; }
            $o = $ov[$seg] ?? null;
            if ($o && ((int) $o['is_hidden'] === 1 || (int) $o['is_disabled'] === 1)) { continue; }
            $items[] = [
                $seg,
                $o && trim((string) $o['label']) !== '' ? (string) $o['label'] : $label,
                $o && trim((string) $o['icon']) !== ''  ? (string) $o['icon']  : $ic,
                $handler,
                $badge,
                $o !== null && $o['sort_order'] !== null ? (int) $o['sort_order'] : $i,
            ];
        }

        /* ⚠️ الترتيب **مستقر**: البنود اللي مالهاش ترتيب مخصَّص بتفضل بترتيب
           الكود، وعنوان القسم بيمشي مع اللي تحته. `usort` مش مستقرة في PHP <8
           بس المشروع على 8.x فهي مستقرة — ومع كده بنستخدم المؤشّر كفاصل. */
        usort($items, static fn($a, $b) => ($a[5] <=> $b[5]));

        // شيل أي عنوان قسم فاضي بعد الفلترة
        $clean = [];
        for ($i = 0, $n = count($items); $i < $n; $i++) {
            $isSection  = $items[$i][2] === null;
            $nextIsLink = isset($items[$i + 1]) && $items[$i + 1][2] !== null;
            if ($isSection && !$nextIsLink) { continue; }
            $clean[] = $items[$i];
        }
        return $clean;
    }

    /**
     * **هل الوحدة دي مطفية؟** — المصدر الوحيد لسؤال الحارس.
     *
     * ⚠️ المخفي (`is_hidden`) بيتشال من القائمة بس والمسار بيفضل شغّال؛
     *    المطفي (`is_disabled`) بيترفض من `Access`. الفرق مقصود: «مش عايزها
     *    في القائمة» غير «مش مستعملينها خالص».
     */
    public static function isDisabled(string $slug): bool
    {
        if (in_array($slug, self::LOCKED, true)) { return false; }
        $o = self::overrides()[$slug] ?? null;
        return $o !== null && (int) $o['is_disabled'] === 1;
    }

    /** المعالِج ده بتاع أنهي وحدة؟ (للحارس في `Access`). */
    public static function slugForHandler(string $handler): ?string
    {
        [$ctrl] = array_pad(explode('@', $handler, 2), 2, '');
        foreach (self::DEFAULTS as $item) {
            if ($item[3] === null) { continue; }
            [$hCtrl] = array_pad(explode('@', (string) $item[3], 2), 2, '');
            if ($hCtrl === $ctrl && $item[0] !== '') { return (string) $item[0]; }
        }
        return null;
    }

    /** صفوف شاشة الإعدادات: الافتراضي + الفرق. */
    public static function adminRows(): array
    {
        $ov = self::overrides();
        $out = [];
        foreach (self::DEFAULTS as $i => $item) {
            [$seg, $label, $ic, $handler] = $item;
            if ($ic === null) { continue; }
            $o = $ov[$seg] ?? null;
            $out[] = [
                'slug'     => $seg,
                'default'  => $label,
                'label'    => $o ? (string) $o['label'] : '',
                'icon'     => $o && trim((string) $o['icon']) !== '' ? (string) $o['icon'] : $ic,
                'order'    => $o && $o['sort_order'] !== null ? (int) $o['sort_order'] : $i,
                'hidden'   => (bool) ($o['is_hidden'] ?? false),
                'disabled' => (bool) ($o['is_disabled'] ?? false),
                'locked'   => in_array($seg, self::LOCKED, true),
            ];
        }
        return $out;
    }

    /**
     * حفظ إعداد وحدة — **للمالك بس** (الحارس في `SettingsController` كمان).
     *
     * @throws \RuntimeException لو حد حاول يطفي وحدة مقفولة
     */
    public static function save(string $slug, array $data): void
    {
        if (!self::ready()) { return; }
        $known = false;
        foreach (self::DEFAULTS as $item) { if ($item[2] !== null && $item[0] === $slug) { $known = true; break; } }
        if (!$known) { throw new \RuntimeException('الوحدة دي مش معروفة.'); }

        $disabled = !empty($data['disabled']) ? 1 : 0;
        $hidden   = !empty($data['hidden']) ? 1 : 0;
        if (in_array($slug, self::LOCKED, true) && ($disabled || $hidden)) {
            throw new \RuntimeException('الوحدة دي طريق الرجوع — ما ينفعش تتطفي ولا تتخفي.');
        }

        Database::exec(
            'INSERT INTO module_settings (slug, label, icon, sort_order, is_hidden, is_disabled, updated_by)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE label = VALUES(label), icon = VALUES(icon),
               sort_order = VALUES(sort_order), is_hidden = VALUES(is_hidden),
               is_disabled = VALUES(is_disabled), updated_by = VALUES(updated_by)',
            [
                $slug,
                trim((string) ($data['label'] ?? '')) ?: null,
                trim((string) ($data['icon'] ?? '')) ?: null,
                isset($data['order']) && $data['order'] !== '' ? (int) $data['order'] : null,
                $hidden, $disabled, Auth::user(),
            ]
        );
        self::$rows = null;
    }
}
