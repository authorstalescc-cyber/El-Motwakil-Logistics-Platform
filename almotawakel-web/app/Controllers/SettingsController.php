<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\App;
use App\Core\Access;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Groq;
use App\Core\Settings;
use App\Core\SettingsSchema;
use App\Models\Lookup;
use App\Models\Permit;
use App\Models\Maintenance;
use App\Models\Debt;
use App\Models\Audit;
use App\Models\UserPref;
use App\Models\NavModule;
use App\Models\AccessOverride;
use App\Models\SettingsHealth;

class SettingsController extends Controller
{
    /**
     * الأقسام اللي ليها تبويب وفورم حفظ عام (docs/19 · المرحلة ١).
     *
     * ⚠️ **مش كل أقسام `SettingsSchema` هنا عن قصد**: `identity` بتتحفظ من فورم
     *    «عام» القديم (مع مفاتيح `business` العامة)، و`permits` ليها فورم خاص
     *    فيه جدول المراحل، و`master` مفاتيحها كلها مُدارة من شاشاتها،
     *    و`maintenance` كلها حالة للعرض. القيمة = أقل دور بيفتح التبويب.
     */
    private const EDITABLE_SECTIONS = [
        'business' => 'owner',      // قواعد مالية — خطر، للمالك بس
        'ops'      => 'owner',      // تشغيل وتتبع ومفاتيح
        'notify'   => 'owner',      // إشعارات الأجهزة
        'security' => 'owner',      // حدود الدخول والجلسات
    ];

    /**
     * مفاتيح فورم «عام» — بتخلط `identity` و`business` زي ما الشاشة عاملة من
     * الأول. **الترتيب هنا هو ترتيب الحفظ مش ترتيب العرض.**
     */
    private const GENERAL_KEYS = [
        'company_name', 'currency', 'company_phone', 'company_email',
        'company_address', 'company_tax_id', 'company_tagline', 'company_activity',
        'vat_rate', 'report_year', 'opening_balance', 'opening_balance_date',
    ];

    /**
     * **خريطة أقسام مركز التحكّم** (docs/19 · المرحلة ٣) — المصدر الوحيد
     * للشريط الجانبي وللمسارات وللبحث مع بعض.
     *
     * ⚠️ **القسم اللي مش هنا مالوش مسار ولا يظهر في الشريط** — فإضافة قسم
     *    جديد سطر واحد، ونسيان تسجيله بيبان فورًا مش بيقع بالسكوت.
     *
     *   view  = ملف الجزء تحت `settings/sections/`
     *   icon  = اسم من كتالوج `icon()` (الاسم الغلط بيرسم دايرة فاضية)
     *   owner = القسم للمالك بس
     */
    private const PAGES = [
        'general' => ['label' => 'عام',                 'icon' => 'home',       'view' => 'general'],
        'modules' => ['label' => 'الوحدات والقائمة',    'icon' => 'menu',       'view' => 'modules', 'owner' => true],
        'lists'   => ['label' => 'القوائم المنسدلة',    'icon' => 'clipboard',  'view' => 'lists'],
        'permits' => ['label' => 'الأذونات والتايم لاين','icon' => 'folder',     'view' => 'permits'],
        'business'=> ['label' => 'قواعد العمل المالية', 'icon' => 'calculator', 'view' => 'registry', 'owner' => true],
        'ops'     => ['label' => 'التشغيل والخدمات',    'icon' => 'cpu',        'view' => 'registry', 'owner' => true],
        'notify'  => ['label' => 'الإشعارات',           'icon' => 'bell',       'view' => 'registry', 'owner' => true],
        'security'=> ['label' => 'الأمان والجلسات',     'icon' => 'shield',     'view' => 'registry', 'owner' => true],
        'display' => ['label' => 'العرض والجداول',      'icon' => 'columns',    'view' => 'display'],
        'ai'      => ['label' => 'المساعد الذكي',       'icon' => 'cpu',        'view' => 'ai',      'owner' => true],
        'users'   => ['label' => 'المستخدمون',          'icon' => 'users',      'view' => 'users',   'owner' => true],
        'access'  => ['label' => 'الصلاحيات',           'icon' => 'shield',     'view' => 'access',  'owner' => true],
        'maint'   => ['label' => 'الصيانة والحالة',     'icon' => 'wrench',     'view' => 'maint'],
        'health'  => ['label' => 'فحص صحة النظام',      'icon' => 'shield',     'view' => 'health',  'owner' => true],
    ];

    /** القسم الافتراضي لما يتفتح `/settings` بلا تحديد. */
    private const DEFAULT_PAGE = 'general';

    public function index(): void
    {
        $this->page(self::DEFAULT_PAGE);
    }

    /**
     * صفحة قسم واحد — **بترندر جزءها وبس**.
     *
     * ⚠️ الشاشة القديمة كانت بترندر الأقسام العشرة كلها في كل زيارة (٦٧١ سطر
     *    وجداول المستخدمين والقوائم والمراحل) عشان تبويب واحد بس هو الظاهر.
     */
    public function page(string $section): void
    {
        $this->requireRole(['owner', 'manager']);
        $owner = Auth::isOwner();

        if (!isset(self::PAGES[$section])) { $section = self::DEFAULT_PAGE; }
        if (!empty(self::PAGES[$section]['owner']) && !$owner) { $section = self::DEFAULT_PAGE; }

        $this->render('settings/index', [
            'section'   => $section,
            'pages'     => $this->visiblePages($owner),
            'pageMeta'  => self::PAGES[$section],
            'searchIndex' => $this->searchIndex($owner),
            'title'   => 'الإعدادات',
            'lookups' => Lookup::withIds(),
            'types'   => Lookup::TYPES,
            'general' => [
                'company_name' => App::setting('company_name', 'شركة المتوكل'),
                'vat_rate'     => App::setting('vat_rate', '0.14'),
                'report_year'  => App::setting('report_year', date('Y')),
                'currency'     => App::setting('currency', 'ج.م'),
                'opening_balance'      => App::setting('opening_balance', '0'),
                'opening_balance_date' => App::setting('opening_balance_date', ''),
                'company_phone'   => App::setting('company_phone', ''),
                'company_address' => App::setting('company_address', ''),
                'company_tax_id'  => App::setting('company_tax_id', ''),
                'company_email'   => App::setting('company_email', ''),
            ],
            /* ═══ أقسام السجل المعرَّف (docs/19 · المرحلة ١) ═══════════════════
               ⚠️ **الشاشة ما بتعرفش أي مفتاح بالاسم** — بتلفّ على السجل. إعداد
                  جديد = سطر في `SettingsSchema` وبيظهر لوحده، و`verify_settings_coverage`
                  بيفشل لو مفتاح قابل للتعديل مالوش خانة. */
            'sections'     => $this->sectionData($owner),
            'sectionMeta'  => SettingsSchema::SECTIONS,
            'stateRows'    => $this->stateRows(),
            'recentChanges'=> Settings::recent(10),
            // سلاسل الإعدادات المؤرَّخة (v74) — «القيمة بقت كذا من تاريخ كذا»
            'chains'       => $owner ? $this->chainData() : [],
            // تفضيلات العرض (v75) — الشركة والمستخدم
            'prefReady'    => UserPref::ready(),
            'prefCompany'  => $owner ? UserPref::companyRows() : [],
            'prefUsers'    => $owner ? UserPref::usersWithPrefs() : 0,
            'prefMine'     => count(UserPref::allFor()),
            // الوحدات والقائمة (v77) — الافتراضي من الكود والفرق من القاعدة
            'modules'      => $owner && NavModule::ready() ? NavModule::adminRows() : [],
            // مصفوفة الصلاحيات (v78) — الإجابة محسوبة من Access::allows نفسها
            'accessMatrix' => $owner && AccessOverride::ready() ? AccessOverride::matrix() : [],
            'accessUsers'  => $owner && AccessOverride::ready() ? AccessOverride::userRows() : [],
            // فحص صحة الإعدادات (v79) — بيقول المشكلة والحل
            'health'       => $owner && $section === 'health' ? SettingsHealth::check() : [],
            'permitCfg'  => Permit::stageConfig(),
            'userState'  => \App\Core\FormState::consume('settings.user.add', 'settings#tab-users'),
            // قواعد مرشد الإيصالات (v32): قوائم التوكيلات — سطر لكل توكيل
            'rcpRules'   => [
                'insurance' => implode("\n", Permit::ruleAgencies('receipt_rules_insurance_agencies')),
                'ateeq'     => implode("\n", Permit::ruleAgencies('receipt_rules_ateeq_agencies')),
            ],
            'permitNums' => [
                // سعر الكشف والضمان صار لكل إذن على حِدة (يُدخَل في الإذن نفسه) — لا سعر عام هنا.
                'inspection_batch'    => App::setting('inspection_batch', '10'),
                'inspection_creditor' => Debt::inspectionAccount()['name'],   // مستحق الكشف
                'guarantee_creditor'  => Debt::guaranteeAccount()['name'],    // مستحق الضمان
                /* v109 — بتترسم بفواصل عشان تبقى سطرًا واحدًا في الشبكة،
                   و`ReceiptLine::carKinds()` بتقبل الفاصلة والسطر الاتنين. */
                'receipt_car_kinds'   => implode('، ', \App\Models\ReceiptLine::carKinds()),
            ],
            'ai' => $owner ? $this->aiSettings() : [],
            'users'  => $owner
                ? Database::all('SELECT id, username, display_name, role, can_free_edit, created_at FROM users ORDER BY id')
                : [],
            'currentUid' => (int)($_SESSION['uid'] ?? 0),
            'canManageSecrets' => $owner,
            'roleLabels' => Access::ROLES,
            // قفل بوابة المناديب للصيانة (v68)
            'repMaint'       => \App\Models\RepMaintenance::state(),
            'repMaintBackAt' => \App\Models\RepMaintenance::backAtLabel(),
        ]);
    }

    /** الأقسام اللي الدور ده بيشوفها — مع شارة العدّ/التنبيه. */
    private function visiblePages(bool $owner): array
    {
        $out = [];
        foreach (self::PAGES as $key => $meta) {
            if (!empty($meta['owner']) && !$owner) { continue; }
            $meta['badge'] = $this->pageBadge($key, $owner);
            $out[$key] = $meta;
        }
        return $out;
    }

    /**
     * شارة القسم — **رقم بيقول حاجة، مش زخرفة** (قاعدة «ممنوع رقم أصمّ»).
     * `null` = مفيش شارة · `warn` = محتاج انتباه.
     */
    private function pageBadge(string $key, bool $owner): ?array
    {
        switch ($key) {
            case 'lists':
                $n = 0;
                foreach (Lookup::withIds() as $arr) { $n += count($arr); }
                return ['text' => (string) $n, 'warn' => false];
            case 'users':
                return $owner
                    ? ['text' => (string) Database::scalar('SELECT COUNT(*) FROM users'), 'warn' => false]
                    : null;
            case 'maint':
                /* ⚠️ **تنبيه وظيفي**: بوابة المناديب مقفولة = المناديب واقفين
                   في الميناء دلوقتي. الشارة دي هي اللي بتمنع نسيانهم. */
                return \App\Models\RepMaintenance::state()['on'] ?? false
                    ? ['text' => 'مقفولة', 'warn' => true] : null;
            case 'display':
                $n = UserPref::ready() ? count(UserPref::allFor()) : 0;
                return $n > 0 ? ['text' => (string) $n, 'warn' => false] : null;
            default:
                return null;
        }
    }

    /**
     * **فهرس البحث** — كل إعداد بتسميته وشرحه وقسمه.
     *
     * ⚠️ بيتبني من `SettingsSchema` فالبحث بيلاقي **كل** مفتاح، حتى اللي في
     *    قسم المالك ما بيفتحوش دلوقتي — وده الفرق بين بحث حقيقي وفلترة للمعروض.
     * ⚠️ الأقسام اللي مالهاش مفاتيح في السجل (القوائم · المستخدمون · الصيانة)
     *    بتتضاف بأسمائها عشان البحث عنها بالاسم يوصّل.
     */
    private function searchIndex(bool $owner): array
    {
        $out = [];
        foreach (SettingsSchema::all() as $key => $raw) {
            $def = SettingsSchema::get($key);
            if ($def['tier'] === SettingsSchema::TIER_STATE) { continue; }
            $page = $this->pageOfSection($def['section']);
            if ($page === null) { continue; }
            if (!empty(self::PAGES[$page]['owner']) && !$owner) { continue; }
            $out[] = [
                'k' => $key,
                't' => $def['label'],
                'h' => $def['help'],
                'p' => $page,
                'pl' => self::PAGES[$page]['label'],
            ];
        }
        foreach (['lists', 'users', 'maint', 'display'] as $page) {
            if (!isset(self::PAGES[$page])) { continue; }
            if (!empty(self::PAGES[$page]['owner']) && !$owner) { continue; }
            $out[] = ['k' => '', 't' => self::PAGES[$page]['label'], 'h' => '',
                      'p' => $page, 'pl' => self::PAGES[$page]['label']];
        }
        return $out;
    }

    /** أي صفحة بتعرض قسم السجل ده؟ */
    private function pageOfSection(string $section): ?string
    {
        if (isset(self::PAGES[$section])) { return $section; }
        // `identity` و`master` بيتعرضوا في صفحة «عام» و«القوائم»
        return match ($section) {
            'identity'    => 'general',
            'master'      => 'lists',
            'maintenance' => 'maint',
            default       => null,
        };
    }

    /**
     * بيانات أقسام السجل للعرض: المفتاح · تعريفه · قيمته الحالية.
     *
     * ⚠️ **الأقسام اللي دورها `owner` بتتشال كلها لغير المالك** — الإخفاء هنا
     *    راحة عرض مش حماية؛ الحماية في `Settings::set()` (قاعدة ③).
     */
    private function sectionData(bool $owner): array
    {
        $out = [];
        foreach (self::EDITABLE_SECTIONS as $section => $minRole) {
            if ($minRole === 'owner' && !$owner) { continue; }
            $fields = [];
            foreach (SettingsSchema::bySection($section) as $key => $def) {
                if (in_array($def['tier'], [SettingsSchema::TIER_STATE, SettingsSchema::TIER_MANAGED], true)) { continue; }
                /* ⚠️ **المفتاح المؤرَّخ ما بياخدش خانة عادية** (v74): خانة واحدة
                   معناها «غيّر القيمة على كل التاريخ» — وهو بالظبط اللي الآلية
                   اتعملت تمنعه. بيتعرض كسلسلة بنقاط تحت. */
                if (in_array($key, Settings::DATED_KEYS, true)) { continue; }
                $fields[$key] = ['def' => $def, 'value' => (string) Settings::get($key)];
            }
            if ($fields) { $out[$section] = $fields; }
        }
        return $out;
    }

    /**
     * سلاسل الإعدادات المؤرَّخة (v74) — نقاط «القيمة بقت كذا من تاريخ كذا».
     *
     * ⚠️ صف الأزل (`1970-01-01`) بيتعلَّم عشان الشاشة توريه كـ«الأساس» بلا
     *    زرار حذف — تعديله أو شيله = إعادة حساب التاريخ كله.
     */
    private function chainData(): array
    {
        if (!Settings::scheduleReady()) { return []; }
        $out = [];
        foreach (Settings::DATED_KEYS as $key) {
            $def = SettingsSchema::get($key);
            if ($def === null) { continue; }
            $rows = Database::all(
                'SELECT * FROM setting_schedules WHERE setting_key = ? ORDER BY effective_from DESC', [$key]
            );
            foreach ($rows as &$r) { $r['is_base'] = (string) $r['effective_from'] === Settings::EPOCH_DATE; }
            unset($r);
            $out[$key] = ['def' => $def, 'rows' => $rows, 'now' => Settings::valueOn($key)];
        }
        return $out;
    }

    /**
     * جدولة تغيير مؤرَّخ — الطريق الوحيد لتغيير إعداد مالي بيمسّ صفوفًا تاريخية.
     *
     * ⚠️ **الحارس في الموديل**: `Settings::schedule()` بتتحقّق من الدور والنوع
     *    والنطاق وبترفض صف الأزل — الكنترولر بيترجم الاستثناء لرسالة وبس.
     */
    public function scheduleSetting(): void
    {
        $this->requireRole('owner');
        $this->requireCsrf();
        $key  = (string) ($_POST['setting_key'] ?? '');
        $from = trim((string) ($_POST['effective_from'] ?? ''));
        $val  = (string) ($_POST['value'] ?? '');
        $note = trim((string) ($_POST['note'] ?? ''));

        try {
            Settings::schedule($key, $from, $val, $note);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 'settings', 'tab-business');
        }
        $this->done('تم تسجيل التغيير — سارٍ من ' . $from . ' واللي قبله ما اتغيّرش.',
            'settings', 'tab-business');
    }

    /** شيل نقطة تغيير مؤرَّخة (صف الأساس محميّ في الموديل). */
    public function unscheduleSetting(string $id): void
    {
        $this->requireRole('owner');
        $this->requireCsrf();
        try {
            Settings::unschedule((int) $id);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 'settings', 'tab-business');
        }
        $this->done('اتشالت نقطة التغيير.', 'settings', 'tab-business');
    }

    /** تصدير الإعدادات JSON — بلا أي مفتاح سرّي. */
    public function exportSettings(): void
    {
        $this->requireRole('owner');
        $data = SettingsHealth::export();
        $name = 'almotawakel-settings-' . date('Ymd-His') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * استيراد ملف إعدادات.
     *
     * ⚠️ **بيمرّ على `Settings::set()`** فالتحقّق والدور والتسجيل في `Audit`
     *    بيتطبّقوا زي أي تعديل يدوي — الاستيراد مش باب خلفي.
     */
    public function importSettings(): void
    {
        $this->requireRole('owner');
        $this->requireCsrf();

        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            $this->fail('اختار ملف الإعدادات الأول.', 'settings/health');
        }
        if ((int) ($file['size'] ?? 0) > 512 * 1024) {
            $this->fail('الملف أكبر من المتوقَّع لملف إعدادات.', 'settings/health');
        }
        $raw = (string) file_get_contents($file['tmp_name']);
        $payload = json_decode($raw, true);
        if (!is_array($payload)) { $this->fail('الملف مش JSON صالح.', 'settings/health'); }

        $r = SettingsHealth::import($payload);
        $msg = "اتطبّق {$r['applied']} إعداد · اتخطّى {$r['skipped']}";
        if ($r['errors']) { $msg .= ' · ' . count($r['errors']) . ' مرفوض: ' . implode(' · ', array_slice($r['errors'], 0, 3)); }
        $this->done($msg, 'settings/health', '', $r['errors'] ? 'error' : 'success');
    }

    /** رجوع إعداد لقيمته السابقة من السجل (v70). */
    public function revertSetting(string $id): void
    {
        $this->requireRole('owner');
        $this->requireCsrf();
        try {
            Settings::revert((int) $id);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 'settings/maint');
        }
        $this->done('رجعت القيمة السابقة.', 'settings/maint');
    }

    /**
     * حفظ استثناء صلاحية (v78).
     *
     * ⚠️ **الحارس في `AccessOverride::set()`**: بيرفض المالك وطريق الرجوع
     *    والكنترولر المجهول — فالطلب المزوَّر ما يقدرش يقفل حد برّه النظام.
     */
    public function saveAccess(): void
    {
        $this->requireRole('owner');
        $this->requireCsrf();
        $ctrl   = (string) ($_POST['controller'] ?? '');
        $effect = (string) ($_POST['effect'] ?? '');
        $role   = trim((string) ($_POST['role'] ?? '')) ?: null;
        $uid    = isset($_POST['user_id']) && $_POST['user_id'] !== '' ? (int) $_POST['user_id'] : null;
        try {
            AccessOverride::set($ctrl, $effect, $uid !== null ? null : $role, $uid);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 'settings/access');
        }
        $this->done($effect === '' ? 'اتشال الاستثناء.' : 'اتسجّل الاستثناء.', 'settings/access');
    }

    /**
     * حفظ ترتيب القائمة وإظهار الوحدات (v77).
     *
     * ⚠️ **الحارس في `NavModule::save()`**: بيرفض أي وحدة مش في `DEFAULTS`
     *    وبيمنع إطفاء الوحدة المقفولة — فحتى الطلب المزوَّر ما يقدرش يقفل
     *    المالك برّه النظام.
     * ⚠️ الحفظ **بالوحدة لا بمسح-وإعادة-بناء**: الفورم بيبعت كل الصفوف، بس
     *    الوحدة اللي مش في الطلب بتفضل زي ما هي (درس `savePermits`).
     */
    public function saveModules(): void
    {
        $this->requireRole('owner');
        $this->requireCsrf();

        $orders   = (array) ($_POST['order'] ?? []);
        $labels   = (array) ($_POST['label'] ?? []);
        $hidden   = (array) ($_POST['hidden'] ?? []);
        $disabled = (array) ($_POST['disabled'] ?? []);

        $errors = [];
        foreach (NavModule::adminRows() as $row) {
            $slug = (string) $row['slug'];
            if (!array_key_exists($slug, $orders) && !array_key_exists($slug, $labels)) { continue; }
            try {
                NavModule::save($slug, [
                    'order'    => $orders[$slug] ?? null,
                    'label'    => $labels[$slug] ?? '',
                    'hidden'   => isset($hidden[$slug]),
                    'disabled' => isset($disabled[$slug]),
                ]);
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($errors) { $this->fail(implode(' · ', array_unique($errors)), 'settings', 'modules'); }
        $this->done('اتحفظت القائمة.', 'settings/modules');
    }

    /**
     * قفل/فتح تفضيل عرض على مستوى الشركة (v75).
     *
     * ⚠️ **القفل مش إخفاء زرار**: `UserPref::set()` بترفض كتابة أي مستخدم على
     *    مفتاح مقفول، و`resolve()` بتتجاهل صفوفه القديمة — فالتثبيت حقيقي حتى
     *    لو الطلب اتبعت بإيد (قاعدة ③).
     */
    public function prefLock(): void
    {
        $this->requireRole('owner');
        $this->requireCsrf();
        $key    = (string) ($_POST['pref_key'] ?? '');
        $value  = (string) ($_POST['pref_value'] ?? '');
        $locked = !empty($_POST['is_locked']);
        try {
            UserPref::setCompany($key, $value, $locked);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 'settings', 'tab-display');
        }
        $this->done($locked ? 'اتثبّت على كل المستخدمين.' : 'بقى افتراضيًّا — والمستخدم يقدر يغيّره.',
            'settings', 'tab-display');
    }

    /** شيل افتراضي شركة (رجوع لافتراضي الكود). */
    public function prefCompanyDelete(): void
    {
        $this->requireRole('owner');
        $this->requireCsrf();
        $key = (string) ($_POST['pref_key'] ?? '');
        if (UserPref::validKey($key)) {
            Database::exec('DELETE FROM user_prefs WHERE user_id = ? AND pref_key = ?',
                [UserPref::COMPANY, $key]);
            UserPref::refreshReady();
        }
        $this->done('اتشال افتراضي الشركة.', 'settings', 'tab-display');
    }

    /**
     * صفوف «حالة النظام» — بيكتبها عامل خلفي والمالك بيقراها بس.
     *
     * ⚠️ ما بتتعرضش كخانات إدخال عن قصد: `Settings::set()` بترفضها، فخانة
     *    قابلة للكتابة كانت هتوعد بحاجة الخادم رافضها.
     */
    private function stateRows(): array
    {
        $rows = [];
        foreach (SettingsSchema::all() as $key => $raw) {
            $def = SettingsSchema::get($key);
            if ($def['tier'] !== SettingsSchema::TIER_STATE) { continue; }
            $rows[] = [
                'label'  => $def['label'],
                'value'  => (string) Settings::get($key),
                'writer' => $def['writer'],
            ];
        }
        return $rows;
    }

    /**
     * قفل/فتح بوابة المناديب للصيانة (v68).
     *
     * ⚠️ **الحارس الحقيقي هنا مش في إخفاء الزرار** — `requireRole` قبل أي
     *    كتابة، و`RepMaintenance` هي اللي بتسجّل في `Audit` وتبعت الإشعار،
     *    فأي طريق تاني للقفل بيعدّي على نفس النقطة.
     *
     * ⚠️ **صفر أثر مالي** — ولا رصيد ولا خزنة ولا عهدة بتتلمس هنا.
     */
    public function repMaintenance(): void
    {
        $this->requireRole(['owner', 'manager']);
        $this->requireCsrf();

        $by = trim((string) (Auth::user() ?? '')) ?: 'المكتب';

        if ((string) ($_POST['action'] ?? '') === 'on') {
            $minutes = trim((string) ($_POST['minutes'] ?? ''));
            \App\Models\RepMaintenance::start(
                (string) ($_POST['message'] ?? ''),
                $minutes === '' ? 0 : (int) $minutes,
                $by
            );
            $this->done('اتقفلت بوابة المناديب واتبعتلهم إشعار.', 'settings', 'rep-maint');
        }

        \App\Models\RepMaintenance::end($by);
        $this->done('اتفتحت بوابة المناديب واتبعتلهم إشعار.', 'settings', 'rep-maint');
    }

    /** إضافة مستخدم جديد (اسم دخول + كلمة سر) */
    public function addUser(): void
    {
        $this->requireRole('owner');
        $this->requireCsrf();
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $display  = trim($_POST['display_name'] ?? '');
        $canFree  = !empty($_POST['can_free_edit']) ? 1 : 0;
        $role     = Access::normalizeRole($_POST['role'] ?? 'read_only');

        if ($username === '' || $password === '') {
            $this->userFailure('لازم تدخل اسم المستخدم وكلمة السر.');
        }
        if (mb_strlen($password) < Auth::minPasswordLength()) {
            $this->userFailure('كلمة السر قصيرة — الحد الأدنى ٨ أحرف.');
        }
        if (Database::row('SELECT id FROM users WHERE username = ?', [$username])) {
            $this->userFailure('اسم المستخدم ده موجود بالفعل — اختار اسم تاني.');
        }

        Database::exec(
            'INSERT INTO users (username, password_hash, display_name, role, can_free_edit) VALUES (?, ?, ?, ?, ?)',
            [$username, password_hash($password, PASSWORD_DEFAULT), $display !== '' ? $display : null, $role, $canFree]
        );
        $newId=Database::lastId();
        Audit::log('create','users',$newId,null,Database::row('SELECT id,username,display_name,role,can_free_edit,created_at FROM users WHERE id=?',[$newId]),'إضافة مستخدم جديد');
        $this->done('تمت إضافة المستخدم «' . ($display !== '' ? $display : $username) . '».', 'settings#tab-users');
    }

    /**
     * فشل إضافة مستخدم — بيرجّع الحقول متعبّية.
     *
     * ⚠️ **كلمة السر مقصود إنها ما ترجعش**: تخزينها في الجلسة عشان نعيد
     * تعبئتها بيحطّها نصًّا صريحًا في مخزن الجلسة على الخادم بلا داعي.
     * نفس اللي بيعمله نموذج الدخول بالظبط.
     */
    private function userFailure(string $msg): never
    {
        $this->formFailure(
            'settings.user.add',
            'settings#tab-users',
            [
                'username'      => trim((string)($_POST['username'] ?? '')),
                'display_name'  => trim((string)($_POST['display_name'] ?? '')),
                'role'          => (string)($_POST['role'] ?? 'read_only'),
                'can_free_edit' => !empty($_POST['can_free_edit']) ? '1' : '',
            ],
            ['_form' => $msg],
            'settings#tab-users'
        );
    }

    /** تعديل مستخدم (اسم الدخول + الاسم الظاهر + كلمة السر اختيارياً) */
    public function updateUser(string $id): void
    {
        $this->requireRole('owner');
        $this->requireCsrf();
        $id = (int)$id;

        $user = Database::row('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$user) {
            flash('error', 'المستخدم غير موجود.');
            redirect('settings#tab-users');
        }

        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $display  = trim($_POST['display_name'] ?? '');
        $canFree  = !empty($_POST['can_free_edit']) ? 1 : 0;
        $role     = Access::normalizeRole($_POST['role'] ?? 'read_only');

        if ($username === '') {
            flash('error', 'اسم المستخدم مطلوب.');
            redirect('settings#tab-users');
        }
        // اسم دخول مكرَّر عند مستخدم آخر؟
        if (Database::row('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $id])) {
            flash('error', 'اسم المستخدم ده مستخدَم بالفعل — اختر اسمًا آخر.');
            redirect('settings#tab-users');
        }
        if (($user['role'] ?? '') === 'owner' && $role !== 'owner'
            && (int) Database::scalar("SELECT COUNT(*) FROM users WHERE role='owner'") <= 1) {
            flash('error', 'لا يمكن تغيير دور آخر مالك في النظام. عيّن مالكًا آخر أولًا.');
            redirect('settings#tab-users');
        }
        // لو أدخل كلمة سر جديدة لازم تكون ٨ أحرف على الأقل
        if ($password !== '' && mb_strlen($password) < Auth::minPasswordLength()) {
            $this->userFailure('كلمة السر قصيرة — الحد الأدنى ٨ أحرف.');
        }

        $before=['id'=>$user['id'],'username'=>$user['username'],'display_name'=>$user['display_name'],'role'=>$user['role']??'read_only','can_free_edit'=>$user['can_free_edit']??0];
        if ($password !== '') {
            Database::exec(
                'UPDATE users SET username = ?, display_name = ?, role = ?, can_free_edit = ?, password_hash = ? WHERE id = ?',
                [$username, $display !== '' ? $display : null, $role, $canFree, password_hash($password, PASSWORD_DEFAULT), $id]
            );
        } else {
            Database::exec(
                'UPDATE users SET username = ?, display_name = ?, role = ?, can_free_edit = ? WHERE id = ?',
                [$username, $display !== '' ? $display : null, $role, $canFree, $id]
            );
        }
        Audit::log('update','users',$id,$before,Database::row('SELECT id,username,display_name,role,can_free_edit FROM users WHERE id=?',[$id]),'تعديل صلاحيات/بيانات مستخدم');

        // لو عدّل حسابه الحالي، حدّث الاسم الظاهر في الجلسة
        if ($id === (int)($_SESSION['uid'] ?? 0)) {
            $_SESSION['uname'] = $display !== '' ? $display : $username;
            $_SESSION['role'] = $role;
            $_SESSION['can_free_edit'] = $canFree;
            if (!$canFree) { \App\Core\Auth::setFreeEditMode(false); }
        }

        flash('success', 'تم تعديل بيانات المستخدم' . ($password !== '' ? ' وتغيير كلمة السر.' : '.'));
        redirect('settings#tab-users');
    }

    /** حذف مستخدم (لا يُسمح بحذف نفسك ولا آخر مستخدم) */
    public function deleteUser(string $id): void
    {
        $this->requireRole('owner');
        $this->requireCsrf();
        $id = (int)$id;

        if ($id === (int)($_SESSION['uid'] ?? 0)) {
            flash('error', 'مينفعش تحذف حسابك اللي داخل بيه دلوقتي.');
            redirect('settings#tab-users');
        }
        if ((int)Database::scalar('SELECT COUNT(*) FROM users') <= 1) {
            flash('error', 'مينفعش تحذف آخر مستخدم في النظام.');
            redirect('settings#tab-users');
        }

        $user=Database::row('SELECT * FROM users WHERE id=?',[$id]);
        if (!$user) { flash('error','المستخدم غير موجود.'); redirect('settings#tab-users'); }
        if (($user['role'] ?? '') === 'owner'
            && (int) Database::scalar("SELECT COUNT(*) FROM users WHERE role='owner'") <= 1) {
            flash('error', 'لا يمكن حذف آخر مالك في النظام.');
            redirect('settings#tab-users');
        }
        $pdo=Database::conn();$pdo->beginTransaction();
        try{Audit::trash('users',$user,$user['display_name']?:$user['username']);Database::exec('DELETE FROM users WHERE id=?',[$id]);$pdo->commit();}
        catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error','تعذّر حذف المستخدم: '.$e->getMessage());redirect('settings#tab-users');}
        flash('success', 'تم نقل المستخدم إلى سلة المحذوفات.');
        redirect('settings#tab-users');
    }

    /** إعدادات المساعد الذكي للعرض في الفورم (مع الرجوع لـ config.php كقيمة أولية) */
    private function aiSettings(): array
    {
        $file = App::config('groq');
        $file = is_array($file) ? $file : [];
        return [
            'enabled'    => App::setting('ai_enabled', !empty($file['enabled']) ? '1' : '0'),
            'active'     => App::setting('ai_active', '1') === '2' ? '2' : '1',
            'model'      => App::setting('ai_model', $file['model'] ?? 'llama-3.3-70b-versatile'),
            'key1'       => '',
            'key1_set'   => $this->aiKey(1) !== '',
            'key1_hint'  => $this->maskedKey($this->aiKey(1)),
            'key1_label' => App::setting('ai_key_1_label', 'مفتاح ١'),
            'key2'       => '',
            'key2_set'   => $this->aiKey(2) !== '',
            'key2_hint'  => $this->maskedKey($this->aiKey(2)),
            'key2_label' => App::setting('ai_key_2_label', 'مفتاح ٢'),
            'models'     => [
                'llama-3.3-70b-versatile',
                'openai/gpt-oss-120b',
                'qwen/qwen3.6-27b',
                'allam-2-7b',
                'meta-llama/llama-4-scout-17b-16e-instruct',
                'llama-3.1-8b-instant',
            ],
        ];
    }

    /** حفظ إعدادات المساعد الذكي (المفاتيح + النشط + الموديل) */
    public function saveAi(): void
    {
        $this->requireRole('owner');
        $this->requireCsrf();
        App::setSetting('ai_enabled', isset($_POST['ai_enabled']) ? '1' : '0');
        App::setSetting('ai_active',  ($_POST['ai_active'] ?? '1') === '2' ? '2' : '1');
        App::setSetting('ai_model',   trim($_POST['ai_model'] ?? '') ?: 'llama-3.3-70b-versatile');
        foreach ([1, 2] as $slot) {
            $posted = trim((string) ($_POST['ai_key_'.$slot] ?? ''));
            if (!empty($_POST['clear_ai_key_'.$slot])) {
                App::setSetting('ai_key_'.$slot, '');
            } elseif ($posted !== '') {
                App::setSetting('ai_key_'.$slot, $posted);
            }
        }
        App::setSetting('ai_key_1_label', trim($_POST['ai_key_1_label'] ?? '') ?: 'مفتاح ١');
        App::setSetting('ai_key_2_label', trim($_POST['ai_key_2_label'] ?? '') ?: 'مفتاح ٢');
        flash('success', 'تم حفظ إعدادات المساعد الذكي.');
        redirect('settings#tab-ai');
    }

    /** اختبار مفتاح + موديل (AJAX JSON) — قبل الاعتماد عليه */
    public function testAi(): void
    {
        $this->requireRole('owner');
        $this->requireCsrf();
        $slot  = (int) ($_POST['slot'] ?? 0);
        $key   = trim($_POST['key'] ?? '');
        if ($key === '' && in_array($slot, [1, 2], true)) { $key = $this->aiKey($slot); }
        $model = trim($_POST['model'] ?? '');
        $this->json(Groq::ping($key, $model));
    }

    /** حفظ إعدادات الأذونات والتايم لاين */
    public function savePermits(): void
    {
        $this->requireRole(['owner', 'manager']);
        $this->requireCsrf();

        // سعر الكشف والضمان لم يعد إعداداً عاماً — يُدخَل لكل إذن في صفحته.
        App::setSetting('inspection_batch',  (string)max(1, (int)($_POST['inspection_batch'] ?? 10)));
        Debt::setInspectionCreditor((string)($_POST['inspection_creditor'] ?? ''));  // مستحق الكشف (يُسمح بالفراغ)
        Debt::setGuaranteeCreditor((string)($_POST['guarantee_creditor'] ?? ''));    // مستحق الضمان

        /* v109 — أنواع الإيصالات اللي بيظهر عليها عدّاد «مش متوزّع».
           ⚠️ **بتتخزّن سطرًا لكل نوع** لأن `SettingsSchema` معرّفها `T_LIST`،
              والشاشة بتاخدها بفواصل — التحويل هنا هو نقطة الالتقاء الوحيدة.
           ⚠️ و**بتتكتب بس لو الفورم بعت الحقل**: الإرسال الجزئي (نفس حماية
              مصفوفة المراحل تحت) ما يصحّش يفضّي القايمة من ورا ظهر المالك. */
        if (array_key_exists('receipt_car_kinds', $_POST)) {
            $kinds = array_values(array_filter(array_map(
                static fn ($k) => trim((string) $k),
                preg_split('/[\r\n,،]+/u', (string) $_POST['receipt_car_kinds']) ?: []
            ), static fn ($k) => $k !== ''));
            App::setSetting('receipt_car_kinds', implode("\n", $kinds));
        }

        // حماية: لا نعيد بناء إعدادات المراحل إلا لو الفورم أرسل مصفوفة المراحل فعلاً.
        // (يمنع تصفير إظهار/أسماء المراحل عند إرسال جزئي للنموذج.)
        if (isset($_POST['stage_label']) && is_array($_POST['stage_label'])) {
            $labels  = $_POST['stage_label'];
            $orders  = $_POST['stage_order']   ?? [];
            $enabled = $_POST['stage_enabled'] ?? [];   // checkbox: المفتاح موجود = مفعّل

            $cfg = [];
            foreach (Permit::STAGES as $key => $meta) {
                $cfg[$key] = [
                    'label'   => trim((string)($labels[$key] ?? '')),
                    'order'   => (int)($orders[$key] ?? 0),
                    'enabled' => in_array($key, Permit::ALWAYS_ON, true) ? true : isset($enabled[$key]),
                ];
            }
            App::setSetting('permit_stages_config', json_encode($cfg, JSON_UNESCAPED_UNICODE));
        }

        // قواعد مرشد الإيصالات (v32): قوائم التوكيلات — تُحفَظ كـ JSON.
        // كل سطر = توكيل. تُحفظ فقط لو أرسل الفورم الحقل (إرسال جزئي لا يصفّرها).
        $splitAgencies = static function (string $raw): array {
            $parts = preg_split('/[\r\n,]+/u', $raw) ?: [];
            $out = [];
            foreach ($parts as $x) { $x = trim($x); if ($x !== '') { $out[] = $x; } }
            return array_values(array_unique($out));
        };
        if (isset($_POST['insurance_agencies'])) {
            App::setSetting('receipt_rules_insurance_agencies',
                json_encode($splitAgencies((string)$_POST['insurance_agencies']), JSON_UNESCAPED_UNICODE));
        }
        if (isset($_POST['ateeq_agencies'])) {
            App::setSetting('receipt_rules_ateeq_agencies',
                json_encode($splitAgencies((string)$_POST['ateeq_agencies']), JSON_UNESCAPED_UNICODE));
        }

        $this->done('تم حفظ إعدادات التايم لاين والأذونات.', 'settings#tab-permits');
    }

    /**
     * حفظ الإعدادات العامة — بقت بتمرّ على `Settings::set()` زي أي قسم تاني.
     *
     * ⚠️ **السلوك ما اتغيّرش**: التسامحات اللي كانت مكتوبة هنا بالإيد
     *    (١٤ تعني ٠.١٤ · التاريخ لازم YYYY-MM-DD وإلا فاضي · `trim`) بقت
     *    معرَّفة في `SettingsSchema::normalize()` بالحرف، و`verify_settings_registry`
     *    بيختبرها. الفرق الوحيد إن الكتابة بقت **بتتحقّق وتسجّل في Audit**.
     */
    public function saveGeneral(): void
    {
        $this->requireRole(['owner', 'manager']);
        $this->requireCsrf();
        $this->writeSection(self::GENERAL_KEYS, 'تم حفظ الإعدادات العامة.', 'tab-general');
    }

    /**
     * **حفظ أي قسم من السجل المعرَّف** — مفيش كود لكل حقل.
     *
     * القسم بيتحدّد من الرابط، والمفاتيح بتتقرا من `SettingsSchema` نفسها.
     * يعني إعداد جديد = سطر في السجل، **وبلا أي تعديل هنا ولا في الشاشة**.
     *
     * ⚠️ **الحارس في الموديل مش هنا** (قاعدة ③): `Settings::set()` بتتحقّق من
     *    الدور والنوع والنطاق. الفحص هنا على القسم بس عشان الرابط ما يفتحش
     *    بابًا لمفتاح برّه الشاشة.
     */
    public function saveSection(string $section): void
    {
        $this->requireRole(['owner', 'manager']);
        $this->requireCsrf();

        if (!isset(SettingsSchema::SECTIONS[$section]) || !isset(self::EDITABLE_SECTIONS[$section])) {
            $this->fail('قسم الإعدادات ده مش معروف.', 'settings');
        }

        $keys = [];
        foreach (SettingsSchema::bySection($section) as $key => $def) {
            // الحالة والمُدار ليهم كتّابهم — `Settings::set()` بترفضهم أصلاً،
            // فاستبعادهم هنا بيمنع رسالة خطأ على حقل الشاشة مش بترسمه.
            if (in_array($def['tier'], [SettingsSchema::TIER_STATE, SettingsSchema::TIER_MANAGED], true)) { continue; }
            $keys[] = $key;
        }
        $this->writeSection($keys, 'تم حفظ ' . SettingsSchema::SECTIONS[$section] . '.', 'tab-' . $section);
    }

    /**
     * الكتابة الفعلية لمجموعة مفاتيح.
     *
     * ⚠️ **المفتاح اللي الفورم ما بعتوش ما بيتلمسش** — نفس درس `savePermits`
     *    الموثَّق: الإرسال الجزئي كان بيصفّر إعدادات مش في النموذج.
     * ⚠️ **المفتاح السرّي الفاضي = إبقاء المحفوظ** لا مسحه.
     */
    private function writeSection(array $keys, string $okMsg, string $anchor): never
    {
        $changed = 0;
        $errors  = [];

        foreach ($keys as $key) {
            if (!array_key_exists($key, $_POST)) { continue; }
            $def = SettingsSchema::get($key);
            $raw = (string) $_POST[$key];
            if ($def !== null && $def['type'] === SettingsSchema::T_SECRET && trim($raw) === '') { continue; }

            try {
                if (Settings::set($key, $raw)) { $changed++; }
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($errors) {
            // ⚠️ **ما بنرجّعش الحقول متعبّية عن قصد**: القيم اللي عدّت اتحفظت
            //    فعلاً، فإعادة تعبئة الفورم بالمرفوض بس كانت هتوري حالة نصّها
            //    محفوظ ونصّها لأ من غير ما تقول أنهي نص.
            $this->fail(implode(' · ', $errors), 'settings', $anchor);
        }
        $this->done($changed > 0 ? $okMsg : 'مفيش حاجة اتغيّرت.', 'settings', $anchor);
    }

    public function addLookup(): void
    {
        $this->requireRole(['owner', 'manager']);
        $this->requireCsrf();
        $type  = $_POST['list_type'] ?? '';
        $value = trim($_POST['value'] ?? '');
        if (!isset(Lookup::TYPES[$type])) { $this->fail('نوع القائمة ده مش معروف.', 'settings#tab-lists'); }
        if ($value === '') { $this->fail('اكتب قيمة العنصر الأول.', 'settings#tab-lists'); }

        // الشاشة دي هي الباب المتعمَّد لإضافة مفردة جديدة — فبتوقف وتخيّر بدل ما تبلع
        // بالسكوت زي المسارات التلقائية. المالك يقدر يأكّد ويعدّي (`confirm_new`).
        if (empty($_POST['confirm_new'])) {
            $key = \App\Models\Maintenance::normkey($value);
            foreach (Lookup::ofType($type) as $existing) {
                if (\App\Models\Maintenance::normkey($existing) === $key) {
                    $this->fail('«' . $existing . '» موجود في القائمة بالفعل — نفس الاسم بتهجئة تانية.', 'settings#tab-lists');
                }
            }
            $g = \App\Models\NameSuggestion::resolve($type, $value);
            if ($g['conflict'] !== null) {
                $this->fail(
                    'القيمة «' . $value . '» قريبة جدًا من «' . $g['conflict'] . '» الموجودة. '
                    . 'استخدم الموجودة، أو أعِد الإضافة مع تأكيد إنها مفردة جديدة فعلاً.',
                    'settings#tab-lists'
                );
            }
        }

        Lookup::add($type, $value, true);
        $this->done('تمت إضافة العنصر.', 'settings#tab-lists');
    }

    public function deleteLookup(string $id): void
    {
        $this->requireRole(['owner', 'manager']);
        $this->requireCsrf();
        Lookup::delete((int)$id);
        $this->done('تم حذف العنصر من القائمة.', 'settings#tab-lists');
    }

    /** توحيد المسميات المكررة تلقائياً (أداة صيانة) */
    public function normalizeNames(): void
    {
        $this->requireRole(['owner', 'manager']);
        $this->requireCsrf();
        $rep = Maintenance::normalizeNames(false);
        $t = $rep['totals'];
        if ($t['merges'] > 0) {
            flash('success', sprintf('تم توحيد المسميات: %d مجموعة مكرَّرة · %d صف مُوحَّد · %d عنصر مكرَّر أُزيل من القوائم.',
                $t['merges'], $t['rows'], $t['lookupDel']));
        } else {
            flash('success', 'لا توجد مسميات مكرَّرة — كل الأسماء موحَّدة بالفعل ✓');
        }
        redirect('settings#tab-maint');
    }

    private function aiKey(int $slot): string
    {
        $file = App::config('groq');
        $fallback = $slot === 1 && is_array($file) ? (string) ($file['api_key'] ?? '') : '';
        return (string) App::setting('ai_key_'.$slot, $fallback);
    }

    private function maskedKey(string $key): string
    {
        if ($key === '') { return ''; }
        $tail = mb_substr($key, -4, null, 'UTF-8');
        return '••••••••' . $tail;
    }
}
