<?php
namespace App\Core;

use InvalidArgumentException;

/**
 * **سجل الإعدادات المعرَّف — المصدر الوحيد لكل مفتاح إعداد في النظام**
 * (docs/19-settings-control-center-plan.md · المرحلة ٠).
 *
 * ═══ اللزمة ═══
 * جرد 2026-08-09 لقى **٤٤ مفتاح** بيقراهم الكود، **٢٠** بس منهم بيتعدّلوا من
 * `/settings`. الباقي بيتكتب من سكربت SSH (`bin/activate_*.php`) أو من هجرة
 * أو **مالوش كاتب خالص** (`push_enabled` — مفتاح إطفاء إشعارات الأجهزة كلها!).
 * وكل واحد فيهم كان معرَّف ضمنيًّا في **مكان الاستدعاء**: النوع والافتراضي
 * والنطاق مكتوبين جوّه `(int) App::setting('x', 48)` وخلاص.
 *
 * ═══ ⚠️⚠️ القاعدة الحاكمة: المفتاح مش معرَّف هنا = مفتاح غير موجود ═══
 * `bin/verify_settings_registry.php` بيمشّط **كل** `App::setting(` و
 * `Settings::get(` في `app/` ويفشل لو لقى مفتاح مش في الجدول ده. يعني مستحيل
 * يرجع «مفتاح يتيم» تاني بالسكوت — لازم يتعرّف هنا الأول.
 *
 * ═══ الحقول ═══
 *   label    — التسمية العربية (اللي بتتعرض للمالك)
 *   section  — قسم مركز التحكّم اللي بيعيش فيه (SECTIONS تحت)
 *   type     — نوع القيمة (T_* تحت) — هو اللي بيحدّد التحقّق ورسم الخانة
 *   default  — الافتراضي **الحالي بالحرف** (منقول من مكان الاستدعاء بلا تغيير)
 *   min/max  — النطاق الصالح للأرقام
 *   options  — قيم T_ENUM المسموحة
 *   role     — أقل دور يقدر يعدّله (`owner` · `manager`)
 *   tier     — TIER_NORMAL عادي · TIER_DANGER مالي بتأكيد مزدوج ·
 *              TIER_STATE حالة بيكتبها عامل (للعرض بس) ·
 *              TIER_MANAGED بيتكتب من موديله هو لا من الشاشة العامة
 *   dated    — القيمة بتتغيّر بـ«سارٍ من تاريخ» لا بأثر رجعي (المرحلة ١)
 *   help     — شرح بالعامية المصرية (صاحب الشركة مش مبرمج)
 *   affects  — الشاشات اللي بتتأثر (قاعدة «ممنوع رقم أصمّ» — الرقم بيوري أثره)
 *   refresh  — دالة تصفير الكاش الساكن. **مصيدة v57 الموثَّقة**: الكاش بيتقرا
 *              قبل الكتابة فبيفضل بالقيمة القديمة بعدها.
 *   writer   — لـTIER_MANAGED/TIER_STATE: مين بيكتبه فعلاً (رسالة الرفض بتقوله)
 *
 * ⚠️ **مفيش هنا:** اتجاه المال · حرّاس CSRF/الصلاحيات · دور اللون (`-fg`) ·
 *    مفاتيح الجداول. دي بنية وحماية — مش تفضيلات (docs/19 §④).
 */
class SettingsSchema
{
    // ── الأنواع ──────────────────────────────────────────────────────────
    public const T_TEXT     = 'text';       // سطر نصّي
    public const T_LONGTEXT = 'longtext';   // نص متعدد الأسطر
    public const T_INT      = 'int';        // عدد صحيح
    public const T_MONEY    = 'money';      // مبلغ (يقبل الفراغ = بلا قيمة)
    public const T_FLOAT    = 'float';      // كسر عشري
    public const T_RATE     = 'rate';       // نسبة 0→1 (بتقبل 14 وتحوّلها 0.14)
    public const T_DATE     = 'date';       // YYYY-MM-DD (الفراغ = مطفي)
    public const T_BOOL     = 'bool';       // '1' / '0'
    public const T_ENUM     = 'enum';       // من options
    public const T_JSON     = 'json';       // JSON صالح
    public const T_SECRET   = 'secret';     // مفتاح/توكن — بيتعرض مقنّعًا
    public const T_LIST     = 'list';       // سطر لكل عنصر

    // ── درجات الخطورة ────────────────────────────────────────────────────
    public const TIER_NORMAL  = 'normal';
    public const TIER_DANGER  = 'danger';    // بيحرّك فلوس — تأكيد مزدوج + مالك
    public const TIER_STATE   = 'state';     // حالة بيكتبها عامل — للعرض بس
    public const TIER_MANAGED = 'managed';   // ليه موديل بيكتبه ويسجّله بنفسه

    /** أقسام مركز التحكّم (docs/19 §⑤). */
    public const SECTIONS = [
        'identity'    => 'الشركة والهوية',
        'business'    => 'قواعد العمل المالية',
        'permits'     => 'الأذونات والتايم لاين',
        'master'      => 'البيانات المرجعية',
        'notify'      => 'الإشعارات',
        'ops'         => 'التشغيل والخدمات',
        'security'    => 'الأمان والجلسات',
        'ai'          => 'المساعد الذكي',
        'maintenance' => 'الصيانة والحالة',
    ];

    /**
     * **مفاتيح تاريخية** — ممكن تكون لسه موجودة في قاعدة الإنتاج، ومفيش كود
     * تشغيل بيقراها. مسجَّلة هنا عشان الفاحص يفرّق بين «مفتاح قديم معروف»
     * و«مفتاح يتيم جديد اتكتب بلا تعريف» — التاني بس هو اللي بيفشّل الفحص.
     *
     * ⚠️ **ممنوع تتشال من القاعدة**: `bin/migrate_v9.php` بيقراها لو اتشغّل
     *    على قاعدة قديمة، وسعر الكشف/الضمان بقى **لكل إذن على حِدة** من بعدها.
     */
    public const LEGACY = [
        'inspection_rate' => 'سعر الكشف العام (اتنقل لكل إذن على حِدة — v9)',
        'guarantee_rate'  => 'سعر الضمان العام (اتنقل لكل إذن على حِدة — v9)',
        /* ⚠️ ده **مفتاح ميت اكتشفه الفاحص** (2026-08-09): موجود بقيمة `200` في
           قاعدة الإنتاج ومن ٢٠٢٦-٠٧-٠٨ في النسخ الاحتياطية، و**ولا سطر كود
           واحد بيقراه**. اتساب مكانه لأن مسحه من القاعدة تغيير بلا فايدة —
           تسجيله هنا بيمنعه إنه يفشّل الفحص وبيوثّق إنه ميت في نفس الوقت. */
        'primary_fees_base' => 'أساس الرسوم الأولية — مفتاح ميت، مفيش كود بيقراه',
    ];

    private static ?array $defs = null;

    /**
     * ⚠️ **كل افتراضي هنا منقول من مكان الاستدعاء بالحرف** — الغرض من المرحلة ٠
     *    إنها **ما تغيّرش أي سلوك**. لو رقم هنا خالف الكود، ده باج مش تحسين.
     */
    private static function defs(): array
    {
        if (self::$defs !== null) { return self::$defs; }

        return self::$defs = [

        // ══════════════ ① الشركة والهوية ══════════════
        'company_name' => [
            'label' => 'اسم الشركة', 'section' => 'identity', 'type' => self::T_TEXT,
            'default' => 'شركة المتوكل', 'role' => 'manager', 'max' => 120,
            'help' => 'بيظهر في ترويسة كل صفحة وفي الفواتير والكشوف.',
            'affects' => ['invoices', 'statements', 'reports'],
        ],
        'company_tagline' => [
            'label' => 'الشعار تحت الاسم', 'section' => 'identity', 'type' => self::T_TEXT,
            'default' => 'لخدمات النقل والتخليص الجمركي', 'role' => 'manager', 'max' => 120,
            'help' => 'السطر الصغير اللي تحت اسم الشركة في تقرير السيارات.',
            'affects' => ['cars'],
        ],
        'company_activity' => [
            'label' => 'نشاط الشركة', 'section' => 'identity', 'type' => self::T_TEXT,
            'default' => 'النقل والتخليص الجمركي', 'role' => 'manager', 'max' => 120,
            'help' => 'بيتطبع في كشف خزنة الشركة.',
            'affects' => ['company-safe'],
        ],
        'company_phone' => [
            'label' => 'تليفون الشركة', 'section' => 'identity', 'type' => self::T_TEXT,
            'default' => '', 'role' => 'manager', 'max' => 60,
            'help' => 'بيظهر في الفاتورة.', 'affects' => ['invoices'],
        ],
        'company_email' => [
            'label' => 'البريد الإلكتروني', 'section' => 'identity', 'type' => self::T_TEXT,
            'default' => '', 'role' => 'manager', 'max' => 120,
            'help' => 'اختياري — بيظهر في الفاتورة.', 'affects' => ['invoices'],
        ],
        'company_address' => [
            'label' => 'عنوان الشركة', 'section' => 'identity', 'type' => self::T_TEXT,
            'default' => '', 'role' => 'manager', 'max' => 200,
            'help' => 'بيظهر في الفاتورة.', 'affects' => ['invoices'],
        ],
        'company_tax_id' => [
            'label' => 'البطاقة الضريبية / السجل التجاري', 'section' => 'identity',
            'type' => self::T_TEXT, 'default' => '', 'role' => 'manager', 'max' => 60,
            'help' => 'اختياري — بيظهر في الفاتورة.', 'affects' => ['invoices'],
        ],
        'currency' => [
            'label' => 'العملة', 'section' => 'identity', 'type' => self::T_TEXT,
            'default' => 'ج.م', 'role' => 'manager', 'max' => 12,
            'help' => 'بتتكتب جنب كل مبلغ في النظام.',
            'affects' => ['invoices', 'reports', 'custody'],
        ],
        'report_year' => [
            'label' => 'سنة التقارير', 'section' => 'identity', 'type' => self::T_INT,
            'default' => '', 'min' => 2000, 'max' => 2100, 'role' => 'manager',
            'help' => 'السنة اللي التقارير بتفتح عليها افتراضيًّا.',
            'affects' => ['reports'],
        ],

        // ══════════════ ② قواعد العمل المالية ══════════════
        /* ── شاشة «حسابي» للمندوب (v72) ──────────────────────────────────
           ⚠️ المفتاحان دول **عرض بحت**: بيغيّروا شكل شاشة المندوب وبس، ولا
              بيحرّكوا مليمًا في خزنة ولا عهدة — عشان كده `TIER_NORMAL` مش
              `TIER_DANGER` رغم إنهم في قسم «قواعد العمل المالية». */
        'payroll_start_day' => [
            'label' => 'يوم بداية شهر الرواتب', 'section' => 'business', 'type' => self::T_INT,
            'default' => '1', 'min' => 1, 'max' => 28, 'role' => 'owner',
            'help' => 'لو الرواتب بتتحسب من ٢٦ للـ٢٥، اكتب ٢٦. أقصى رقم ٢٨ عشان فبراير.',
            'affects' => ['reps'],
        ],
        'rep_account_expense_kinds' => [
            'label' => 'مصروفات تظهر للمندوب في «حسابي»', 'section' => 'business',
            'type' => self::T_TEXT, 'default' => 'مواصلات داخلية، مواصلات خارجية',
            'role' => 'owner', 'max' => 255,
            'help' => 'اكتب الأنواع بالعربي مفصولة بفاصلة: مواصلات داخلية، مواصلات خارجية، نثرية عامة. عرض للعلم بس — مش بتتخصم من راتبه.',
            'affects' => ['reps'],
        ],
        /* ── رواتب المناديب (v87) ────────────────────────────────────────
           ⚠️ التلاتة دول **بيحرّكوا فلوس فعلاً** (بيحدّدوا حوض الكومسيون
              وحوض المصروف اللي بيتصرفوا للمناديب) — عشان كده `TIER_DANGER`
              على عكس إعدادات «حسابي» العرضية فوق. */
        'rep_expense_per_bill' => [
            'label' => 'مصروف المناديب على البوليصة', 'section' => 'business',
            'type' => self::T_MONEY, 'default' => '150', 'min' => 0, 'max' => 10000,
            'role' => 'owner', 'tier' => self::TIER_DANGER, 'dated' => true,
            'help' => 'المبلغ اللي بيتحطّ للمناديب على كل بوليصة. المصروف المسموح بيتخصم منه والباقي بيتقسم عليهم.',
            'affects' => ['reps'],
        ],
        'payroll_excluded_container_types' => [
            'label' => 'شحنات برّه حساب المناديب', 'section' => 'business',
            'type' => self::T_LIST, 'default' => "جامبو\nتانك",
            'role' => 'owner', 'tier' => self::TIER_DANGER,
            'help' => 'نوع في كل سطر — البوالص دي ما بتدخلش لا حوض الكومسيون ولا حوض المصروف.',
            'affects' => ['reps'],
        ],
        /* ⚠️ `T_LIST` مش `T_TEXT`: قاعدة السجل بتلزم أي إعداد مالي خطر إنه
           يكون «سارٍ من تاريخ» **إلا القوايم** — والقايمة هنا فعلاً مش معدّلًا
           يتأرّخ، هي اختيار أنواع. (verify_settings_registry بيفشل لو رجعت T_TEXT.) */
        'payroll_allowed_expense_kinds' => [
            'label' => 'المصروف المسموح للمناديب', 'section' => 'business',
            'type' => self::T_LIST, 'default' => "مواصلات داخلية\nتصوير وطباعة",
            'role' => 'owner', 'tier' => self::TIER_DANGER,
            'help' => 'نوع في كل سطر — دول اللي بيتخصموا من حوض المصروف. أي نوع تاني بيتحمّله المكتب.',
            'affects' => ['reps'],
        ],
        'vat_rate' => [
            'label' => 'نسبة القيمة المضافة', 'section' => 'business', 'type' => self::T_RATE,
            'default' => '0.14', 'min' => 0, 'max' => 1, 'role' => 'manager',
            'help' => 'اكتب 0.14 أو 14 — الاتنين بيتقبلوا. بتتحسب على النولون بس.',
            'affects' => ['invoices'],
        ],
        'opening_balance' => [
            'label' => 'الرصيد الافتتاحي (باقي عهدة سابقة)', 'section' => 'business',
            'type' => self::T_MONEY, 'default' => '0', 'role' => 'manager',
            'help' => 'الفلوس اللي كانت في الخزنة قبل ما النظام يشتغل.',
            'affects' => ['closing', 'custody'],
        ],
        'opening_balance_date' => [
            'label' => 'تاريخ بداية الرصيد الافتتاحي', 'section' => 'business',
            'type' => self::T_DATE, 'default' => '', 'role' => 'manager',
            'help' => 'من التاريخ ده والنظام بيحسب. سيبه فاضي يعني من الأول.',
            'affects' => ['closing'],
        ],
        'custody_bill_deduction' => [
            'label' => 'المخصوم من عهدة كل بوليصة', 'section' => 'business',
            'type' => self::T_MONEY, 'default' => '100', 'min' => 0, 'max' => 10000,
            'role' => 'owner', 'tier' => self::TIER_DANGER, 'dated' => true,
            'help' => 'المبلغ اللي بينزل من عهدة السائق على كل بوليصة.',
            'affects' => ['custody', 'reps', 'closing'],
        ],
        'custody_deduction_exempt_cars' => [
            'label' => 'العربيات المعفاة من الخصم', 'section' => 'business',
            'type' => self::T_LIST, 'default' => 'س ع م 8737',
            'role' => 'owner', 'tier' => self::TIER_DANGER,
            'help' => 'لوحة في كل سطر — العربيات دي عهدتها ما بيتخصمش منها.',
            'affects' => ['custody', 'reps'],
        ],
        'company_safe_monthly_budget' => [
            'label' => 'ميزانية خزنة الشركة الشهرية', 'section' => 'business',
            'type' => self::T_MONEY, 'default' => '', 'min' => 0, 'role' => 'owner',
            'help' => 'سيبها فاضية يعني بلا سقف ومن غير تنبيه.',
            'affects' => ['company-safe'],
        ],
        'rep_custody_epoch' => [
            'label' => 'خط فصل عهد المناديب', 'section' => 'business', 'type' => self::T_DATE,
            'default' => '', 'role' => 'owner', 'tier' => self::TIER_DANGER,
            'help' => 'الحركات قبل التاريخ ده ما بتدخلش في رصيد المندوب. فاضي = مطفي.',
            'affects' => ['rep-custody', 'reps'],
            'refresh' => ['\App\Models\RepCustody', 'refreshEpoch'],
        ],
        'rep_safe_rule_from' => [
            'label' => 'من إمتى الدرج بطّل يمتصّ صرف المندوب', 'section' => 'business',
            'type' => self::T_DATE, 'default' => '', 'role' => 'owner',
            'tier' => self::TIER_DANGER,
            'help' => 'قاعدة «رقم الخزنة = الدرج بس». فاضي = مطفي.',
            'affects' => ['custody', 'closing', 'rep-custody'],
            'refresh' => ['\App\Models\RepCustody', 'refreshSafeRule'],
        ],
        /* ⚠️ **المفتاح ده اكتشفه الفاحص على قاعدة الإنتاج وحدها** (2026-08-10):
           موجود هناك ومش موجود محليًّا، لأن `bin/activate_drawer_only.php` بيكتبه
           **على السيرفر بس** عشان يفتكر القيمة القديمة قبل ما يقلب النموذج —
           و`--off` بترجّعها منه. ده **رمز تراجع** لا إعداد: مالوش خانة ولا
           بيتعدّل من الشاشة، بس تغييره بيتسجّل (مش زي مفاتيح الحالة). */
        'rep_bank_topup_from_prev' => [
            'label' => 'القيمة السابقة لقاعدة «الخزنة = كل كاش الشركة»',
            'section' => 'business', 'type' => self::T_DATE, 'default' => '',
            'role' => 'owner', 'tier' => self::TIER_MANAGED,
            'writer' => 'bin/activate_drawer_only.php',
            'help' => 'بيتحفظ تلقائيًّا عشان `--off` تقدر ترجّع القاعدة زي ما كانت.',
            'affects' => ['custody', 'closing'],
        ],
        'rep_bank_topup_from' => [
            'label' => 'من إمتى «الخزنة = كل كاش الشركة»', 'section' => 'business',
            'type' => self::T_DATE, 'default' => '', 'role' => 'owner',
            'tier' => self::TIER_DANGER,
            'help' => 'أي عهدة تنزل للمندوب من البنك تتحسب زيادة في الخزنة. فاضي = مطفي.',
            'affects' => ['custody', 'closing', 'rep-custody'],
            'refresh' => ['\App\Models\RepCustody', 'refreshBankTopup'],
        ],

        // ══════════════ ③ الأذونات والتايم لاين ══════════════
        'inspection_batch' => [
            'label' => 'أقصى عدد حاويات للكشف/الضمان الواحد', 'section' => 'permits',
            'type' => self::T_INT, 'default' => '10', 'min' => 1, 'max' => 1000,
            'role' => 'manager',
            'help' => 'الكشف الواحد بيغطّي كام حاوية قبل ما يتحسب كشف تاني.',
            'affects' => ['permits', 'debts'],
        ],
        'permit_stages_config' => [
            'label' => 'إعداد مراحل التايم لاين', 'section' => 'permits', 'type' => self::T_JSON,
            'default' => '', 'role' => 'manager',
            'help' => 'اسم كل مرحلة وترتيبها وهل ظاهرة — بتتعدّل من جدول المراحل.',
            'affects' => ['permits'],
        ],
        'receipt_rules_insurance_agencies' => [
            'label' => 'توكيلات التأمين (مرشد الإيصالات)', 'section' => 'permits',
            'type' => self::T_JSON, 'default' => '', 'role' => 'manager',
            'help' => 'التوكيلات اللي التأمين عندها بيغني عن إيصال التخصيص للصادر.',
            'affects' => ['permits'],
        ],
        'receipt_rules_ateeq_agencies' => [
            'label' => 'توكيلات تعتيق الحاويات', 'section' => 'permits',
            'type' => self::T_JSON, 'default' => '', 'role' => 'manager',
            'help' => 'التوكيلات اللي بتطلب تعتيق حاويات للصادر.',
            'affects' => ['permits'],
        ],

        // ══════════════ ④ البيانات المرجعية ══════════════
        'data_aliases' => [
            'label' => 'الأسماء البديلة الموحَّدة', 'section' => 'master', 'type' => self::T_JSON,
            'default' => '', 'role' => 'manager', 'tier' => self::TIER_MANAGED,
            'writer' => 'شاشة تنظيف البيانات /quality',
            'help' => 'تهجئات مختلفة لنفس الاسم اتدمجت في اسم واحد معتمد.',
            'affects' => ['quality'],
            'refresh' => ['\App\Models\DataAlias', 'clearCache'],
        ],
        'car_ignored_plates' => [
            'label' => 'لوحات مستبعَدة من سيارات المكتب', 'section' => 'master',
            'type' => self::T_JSON, 'default' => '', 'role' => 'manager',
            'tier' => self::TIER_MANAGED, 'writer' => 'شاشة سيارات المكتب /cars',
            'help' => 'اللوحات اللي المالك قال إنها مش عربيات مكتب.',
            'affects' => ['cars'],
            'refresh' => ['\App\Models\OfficeCar', 'clearCache'],
        ],

        // ══════════════ ⑤ الإشعارات ══════════════
        'push_enabled' => [
            'label' => 'تشغيل إشعارات الأجهزة', 'section' => 'notify', 'type' => self::T_BOOL,
            'default' => '1', 'role' => 'owner',
            'help' => 'مفتاح إطفاء عام — بيوقف كل إشعارات التطبيق على كل الأجهزة.',
            'affects' => ['push', 'notifications'],
        ],
        'push_vapid_public' => [
            'label' => 'مفتاح VAPID العام', 'section' => 'notify', 'type' => self::T_SECRET,
            'default' => '', 'role' => 'owner',
            'help' => 'مفتاح إشعارات المتصفح. تغييره بيبطّل اشتراكات الأجهزة الحالية.',
            'affects' => ['push'],
        ],
        'push_vapid_private' => [
            'label' => 'مفتاح VAPID الخاص', 'section' => 'notify', 'type' => self::T_SECRET,
            'default' => '', 'role' => 'owner',
            'help' => 'زي كلمة السر — بلاش تنشره لأي حد.',
            'affects' => ['push'],
        ],

        // ══════════════ ⑥ التشغيل والخدمات ══════════════
        'public_base_url' => [
            'label' => 'الرابط العام للنظام', 'section' => 'ops', 'type' => self::T_TEXT,
            'default' => '', 'role' => 'owner', 'max' => 200,
            'help' => 'الرابط اللي بيتبعت للسائق في الواتساب لتتبع الشحنة.',
            'affects' => ['tracking', 'notifications'],
        ],
        'track_expire_hours' => [
            'label' => 'صلاحية رابط التتبع (ساعات)', 'section' => 'ops', 'type' => self::T_INT,
            'default' => '48', 'min' => 1, 'max' => 720, 'role' => 'manager',
            'help' => 'بعد المدة دي الرابط بيبطّل يشتغل للسائق.',
            'affects' => ['tracking'],
        ],
        'track_live_secs' => [
            'label' => 'كل قد إيه الموقع بيتحدّث (ثواني)', 'section' => 'ops',
            'type' => self::T_INT, 'default' => '30', 'min' => 5, 'max' => 3600,
            'role' => 'manager',
            'help' => 'أقل رقم = تتبع أدق وبطارية أسرع في الخلوص.',
            'affects' => ['tracking'],
        ],
        'track_avg_speed_kmh' => [
            'label' => 'متوسط السرعة المفترض (كم/س)', 'section' => 'ops', 'type' => self::T_INT,
            'default' => '45', 'min' => 5, 'max' => 150, 'role' => 'manager',
            'help' => 'بيتحسب بيه الوقت المتوقّع للوصول.',
            'affects' => ['tracking'],
        ],
        'track_road_factor' => [
            'label' => 'معامل التواء الطريق', 'section' => 'ops', 'type' => self::T_FLOAT,
            'default' => '1.3', 'min' => 1, 'max' => 3, 'role' => 'manager',
            'help' => 'المسافة على الأرض أطول من الخط المستقيم — ١.٣ يعني ٣٠٪ زيادة.',
            'affects' => ['tracking'],
        ],

        // ══════════════ ⑦ المساعد الذكي ══════════════
        'ai_enabled' => [
            'label' => 'تفعيل المساعد الذكي', 'section' => 'ai', 'type' => self::T_BOOL,
            'default' => '0', 'role' => 'owner',
            'help' => 'لما يتقفل الشاشة بتفضل موجودة بس ما بتردّش.',
            'affects' => ['assistant'],
        ],
        'ai_active' => [
            'label' => 'المفتاح النشط', 'section' => 'ai', 'type' => self::T_ENUM,
            'default' => '1', 'options' => ['1', '2'], 'role' => 'owner',
            'help' => 'أنهي مفتاح من الاتنين اللي بيشتغل دلوقتي.',
            'affects' => ['assistant'],
        ],
        'ai_model' => [
            'label' => 'الموديل المستخدم', 'section' => 'ai', 'type' => self::T_TEXT,
            'default' => 'llama-3.3-70b-versatile', 'role' => 'owner', 'max' => 120,
            'help' => 'llama-3.3-70b سريع وعربيته كويسة · allam-2-7b متخصص عربي.',
            'affects' => ['assistant'],
        ],
        'ai_key_1' => [
            'label' => 'مفتاح Groq الأول', 'section' => 'ai', 'type' => self::T_SECRET,
            'default' => '', 'role' => 'owner',
            'help' => 'زي كلمة السر — بلاش تنشره.', 'affects' => ['assistant'],
        ],
        'ai_key_2' => [
            'label' => 'مفتاح Groq التاني', 'section' => 'ai', 'type' => self::T_SECRET,
            'default' => '', 'role' => 'owner',
            'help' => 'مفتاح احتياطي تبدّل عليه لو الأول خلص رصيده.',
            'affects' => ['assistant'],
        ],
        'ai_key_1_label' => [
            'label' => 'اسم المفتاح الأول', 'section' => 'ai', 'type' => self::T_TEXT,
            'default' => 'مفتاح ١', 'role' => 'owner', 'max' => 60,
            'help' => 'اسم بتعرفه بيه انت بس.', 'affects' => ['assistant'],
        ],
        'ai_key_2_label' => [
            'label' => 'اسم المفتاح التاني', 'section' => 'ai', 'type' => self::T_TEXT,
            'default' => 'مفتاح ٢', 'role' => 'owner', 'max' => 60,
            'help' => 'اسم بتعرفه بيه انت بس.', 'affects' => ['assistant'],
        ],


        // ══════════════ ⑨ عتبات الإشعارات (v87 — كانت ثوابت في NotifyWatch) ══
        'notify_submission_warn_hours' => [
            'label' => 'بند المندوب يبقى «متأخّر» بعد (ساعة)', 'section' => 'notify',
            'type' => self::T_INT, 'default' => '4', 'min' => 1, 'max' => 168, 'role' => 'manager',
            'help' => 'البند المستني مراجعة أكتر من كده بيتعلّم متأخّر.',
            'affects' => ['rep-custody', 'notifications'],
        ],
        'notify_submission_urgent_hours' => [
            'label' => 'ويبقى «عاجل» بعد (ساعة)', 'section' => 'notify',
            'type' => self::T_INT, 'default' => '24', 'min' => 1, 'max' => 336, 'role' => 'manager',
            'help' => 'لازم يكون أكبر من ساعات «متأخّر».',
            'affects' => ['rep-custody', 'notifications'],
        ],
        'notify_permit_idle_days' => [
            'label' => 'إذن واقف بلا حركة (يوم)', 'section' => 'notify',
            'type' => self::T_INT, 'default' => '7', 'min' => 1, 'max' => 120, 'role' => 'manager',
            'help' => 'بعدها بيوصلك تنبيه إن الإذن مالوش حركة.',
            'affects' => ['permits', 'notifications'],
        ],
        'notify_closing_late_days' => [
            'label' => 'تأخّر التقفيل (يوم)', 'section' => 'notify',
            'type' => self::T_INT, 'default' => '2', 'min' => 1, 'max' => 60, 'role' => 'manager',
            'help' => 'بعدها بيتبعت تنبيه إن فيه أيام مش متقفلة.',
            'affects' => ['closing', 'notifications'],
        ],
        'notify_custody_low' => [
            'label' => 'تنبيه «العهدة قربت تخلص» عند', 'section' => 'notify',
            'type' => self::T_MONEY, 'default' => '500', 'min' => 0, 'max' => 1000000, 'role' => 'manager',
            'help' => 'لما رصيد المندوب ينزل تحت الرقم ده بيوصلك تنبيه.',
            'affects' => ['rep-custody', 'notifications'],
        ],
        'notify_digest_hour' => [
            'label' => 'ميعاد الملخّص اليومي (ساعة)', 'section' => 'notify',
            'type' => self::T_INT, 'default' => '8', 'min' => 0, 'max' => 23, 'role' => 'manager',
            'help' => 'بتوقيت القاهرة — ٨ يعني ٨ الصبح.',
            'affects' => ['notifications'],
        ],

        // ══════════════ ⑩ حدود الأمان (v87 — كانت ثوابت في Auth/RepAuth) ══
        'auth_max_tries' => [
            'label' => 'محاولات الدخول قبل القفل', 'section' => 'security',
            'type' => self::T_INT, 'default' => '5', 'min' => 3, 'max' => 20, 'role' => 'owner',
            'help' => 'بعدها الحساب بيتقفل مؤقتًا.',
            'affects' => ['security'],
        ],
        'auth_lock_minutes' => [
            'label' => 'مدة قفل الحساب (دقيقة)', 'section' => 'security',
            'type' => self::T_INT, 'default' => '10', 'min' => 1, 'max' => 1440, 'role' => 'owner',
            'help' => 'بعدها الحساب بيفتح لوحده.',
            'affects' => ['security'],
        ],
        'auth_idle_minutes' => [
            'label' => 'خروج تلقائي بعد خمول (دقيقة)', 'section' => 'security',
            'type' => self::T_INT, 'default' => '120', 'min' => 5, 'max' => 1440, 'role' => 'owner',
            'help' => 'لجلسة المكتب. بوابة المندوب ليها رقمها.',
            'affects' => ['security'],
        ],
        'auth_min_password' => [
            'label' => 'أقل طول لكلمة السر', 'section' => 'security',
            'type' => self::T_INT, 'default' => '8', 'min' => 6, 'max' => 64, 'role' => 'owner',
            'help' => 'بيتطبّق على كلمات السر الجديدة بس.',
            'affects' => ['users', 'security'],
        ],
        'rep_idle_minutes' => [
            'label' => 'خمول بوابة المندوب (دقيقة)', 'section' => 'security',
            'type' => self::T_INT, 'default' => '480', 'min' => 30, 'max' => 2880, 'role' => 'owner',
            'help' => 'المندوب في الميدان محتاج مدة أطول من المكتب.',
            'affects' => ['security'],
        ],
        // ══════════════ ⑧ الصيانة والحالة ══════════════
        /* بوابة المناديب: `RepMaintenance` بتكتبها **وبتسجّل في Audit وتبعت
           إشعار** — فالكتابة العامة عليها بتتمنع، والرسالة بتوجّه للمكان الصح. */
        'rep_portal_maint' => [
            'label' => 'بوابة المناديب مقفولة', 'section' => 'maintenance', 'type' => self::T_BOOL,
            'default' => '0', 'role' => 'manager', 'tier' => self::TIER_MANAGED,
            'writer' => 'كارت «بوابة المناديب» في الإعدادات',
            'help' => 'لما تتقفل المندوب بيشوف شاشة «النظام بيتظبّط».',
            'affects' => ['settings'],
        ],
        'rep_portal_maint_msg' => [
            'label' => 'رسالة الصيانة للمناديب', 'section' => 'maintenance',
            'type' => self::T_TEXT, 'default' => '', 'role' => 'manager',
            'tier' => self::TIER_MANAGED, 'writer' => 'كارت «بوابة المناديب» في الإعدادات',
            'help' => 'الرسالة اللي المندوب بيشوفها وهو مستني.', 'affects' => ['settings'],
        ],
        'rep_portal_maint_until' => [
            'label' => 'ميعاد فتح البوابة تلقائيًّا', 'section' => 'maintenance',
            'type' => self::T_INT, 'default' => '0', 'min' => 0, 'role' => 'manager',
            'tier' => self::TIER_MANAGED, 'writer' => 'كارت «بوابة المناديب» في الإعدادات',
            'help' => 'صفر يعني هتفضل مقفولة لحد ما تفتحها بإيدك.', 'affects' => ['settings'],
        ],
        'rep_portal_maint_started' => [
            'label' => 'وقت بداية الصيانة', 'section' => 'maintenance', 'type' => self::T_INT,
            'default' => '0', 'min' => 0, 'role' => 'manager', 'tier' => self::TIER_MANAGED,
            'writer' => 'كارت «بوابة المناديب» في الإعدادات',
            'help' => 'بيتسجّل لوحده وقت القفل.', 'affects' => ['settings'],
        ],

        /* ── حالة بيكتبها عامل خلفي — للعرض بس، وما بتدخلش سجل التغييرات ────
           (وإلا نبضة كل ٥ دقايق تملا السجل وتدفن التغييرات الحقيقية.) */
        'backup_last_ok' => [
            'label' => 'آخر نسخة احتياطية ناجحة', 'section' => 'maintenance',
            'type' => self::T_TEXT, 'default' => '', 'role' => 'owner',
            'tier' => self::TIER_STATE, 'writer' => 'النسخة اليومية التلقائية',
            'help' => 'بيتحدّث لوحده بعد كل نسخة كملت.', 'affects' => ['security'],
        ],
        'backup_last_msg' => [
            'label' => 'رسالة آخر نسخة احتياطية', 'section' => 'maintenance',
            'type' => self::T_TEXT, 'default' => '', 'role' => 'owner',
            'tier' => self::TIER_STATE, 'writer' => 'النسخة اليومية التلقائية',
            'help' => 'تفاصيل نجاح أو فشل آخر نسخة.', 'affects' => ['security'],
        ],
        /* ⚠️ مختلفة عن `backup_last_ok` عن قصد: دي بتتحدّث مع **كل محاولة**
              حتى الفاشلة، عشان تخنق إعادة المحاولة على ساعة. لو الاتنين
              بقوا مفتاحًا واحدًا، الفشل كان هيمسح دليل «آخر نجاح» ويخلّي
              التوقّف صامتًا. */
        'backup_last_try' => [
            'label' => 'آخر محاولة نسخة احتياطية', 'section' => 'maintenance',
            'type' => self::T_TEXT, 'default' => '', 'role' => 'owner',
            'tier' => self::TIER_STATE, 'writer' => 'النسخة اليومية التلقائية',
            'help' => 'نجحت أو فشلت — بيمنع تكرار المحاولة كل ٥ دقايق.',
            'affects' => ['security'],
        ],
        'notify_digest_last' => [
            'label' => 'آخر ملخّص يومي اتبعت', 'section' => 'maintenance', 'type' => self::T_TEXT,
            'default' => '', 'role' => 'owner', 'tier' => self::TIER_STATE,
            'writer' => 'NotifyWatch', 'help' => 'بيتحدّث لوحده.', 'affects' => ['notifications'],
        ],
        'push_worker_last_run' => [
            'label' => 'آخر تشغيل لعامل الإشعارات', 'section' => 'maintenance',
            'type' => self::T_TEXT, 'default' => '', 'role' => 'owner',
            'tier' => self::TIER_STATE, 'writer' => 'bin/push_worker.php',
            'help' => 'لو الوقت ده قديم يبقى العامل واقف.', 'affects' => ['push'],
        ],
        'push_worker_last_stat' => [
            'label' => 'حصيلة آخر تشغيل للعامل', 'section' => 'maintenance',
            'type' => self::T_TEXT, 'default' => '', 'role' => 'owner',
            'tier' => self::TIER_STATE, 'writer' => 'bin/push_worker.php',
            'help' => 'اتبعت كام إشعار وفشل كام.', 'affects' => ['push'],
        ],
        'dq_same_count' => [
            'label' => 'عدّاد شارة تنظيف البيانات', 'section' => 'maintenance',
            'type' => self::T_INT, 'default' => '0', 'min' => 0, 'role' => 'manager',
            'tier' => self::TIER_STATE, 'writer' => 'DataQuality',
            'help' => 'كاش داخلي لعدد الأسماء المكرَّرة.', 'affects' => ['quality'],
        ],
        'dq_same_count_at' => [
            'label' => 'وقت آخر عدّ للأسماء المكرَّرة', 'section' => 'maintenance',
            'type' => self::T_INT, 'default' => '0', 'min' => 0, 'role' => 'manager',
            'tier' => self::TIER_STATE, 'writer' => 'DataQuality',
            'help' => 'كاش داخلي بيتجدّد كل ساعة.', 'affects' => ['quality'],
        ],
        ];
    }

    /** @return array<string,array> كل المفاتيح المعرَّفة. */
    public static function all(): array { return self::defs(); }

    public static function has(string $key): bool { return isset(self::defs()[$key]); }

    /** تعريف مفتاح، أو null لو مش معرَّف. */
    public static function get(string $key): ?array
    {
        $d = self::defs()[$key] ?? null;
        if ($d === null) { return null; }
        return $d + [
            'key' => $key, 'tier' => self::TIER_NORMAL, 'role' => 'owner',
            'dated' => false, 'help' => '', 'affects' => [], 'refresh' => null,
            'writer' => '', 'options' => [], 'min' => null, 'max' => null,
        ];
    }

    /** مفاتيح قسم واحد بترتيب تعريفها. */
    public static function bySection(string $section): array
    {
        $out = [];
        foreach (self::defs() as $key => $d) {
            if (($d['section'] ?? '') === $section) { $out[$key] = self::get($key); }
        }
        return $out;
    }

    /** المفاتيح اللي بيتعدّلوا من الشاشة فعلاً (بلا الحالة ولا المُدارة). */
    public static function editable(): array
    {
        $out = [];
        foreach (self::defs() as $key => $d) {
            $tier = $d['tier'] ?? self::TIER_NORMAL;
            if ($tier === self::TIER_STATE || $tier === self::TIER_MANAGED) { continue; }
            $out[$key] = self::get($key);
        }
        return $out;
    }

    /** الافتراضي المعرَّف للمفتاح (نص دايمًا — زي ما القاعدة بتخزّنه). */
    public static function defaultOf(string $key): string
    {
        $d = self::defs()[$key] ?? null;
        if ($d === null) { return ''; }
        // `report_year` افتراضيه السنة الحالية — ما ينفعش يتثبّت في مصفوفة ساكنة.
        if ($key === 'report_year' && (string) ($d['default'] ?? '') === '') { return date('Y'); }
        return (string) ($d['default'] ?? '');
    }

    /**
     * **تطبيع وتحقّق — البوابة الوحيدة لأي قيمة داخلة.**
     *
     * بترجّع القيمة متطبَّعة كنص، أو بترمي `InvalidArgumentException` برسالة
     * عربية جاهزة للعرض. **الرفض هو الافتراضي**: نوع مش معروف = رفض.
     *
     * @throws InvalidArgumentException
     */
    public static function normalize(string $key, $raw): string
    {
        $d = self::get($key);
        if ($d === null) {
            throw new InvalidArgumentException("الإعداد «{$key}» مش معرَّف في سجل الإعدادات.");
        }
        $label = $d['label'];
        $v = is_string($raw) ? trim($raw) : (is_scalar($raw) ? trim((string) $raw) : '');

        switch ($d['type']) {
            case self::T_TEXT:
            case self::T_SECRET:
                $v = preg_replace('/[\r\n\t]+/u', ' ', $v) ?? $v;
                if ($d['max'] !== null && mb_strlen($v) > (int) $d['max']) {
                    throw new InvalidArgumentException("«{$label}» أطول من المسموح ({$d['max']} حرف).");
                }
                return $v;

            case self::T_LONGTEXT:
            case self::T_LIST:
                return str_replace("\r\n", "\n", $v);

            case self::T_BOOL:
                return in_array($v, ['1', 'on', 'true', 'yes'], true) ? '1' : '0';

            case self::T_INT:
                if ($v === '') { return ''; }
                if (!preg_match('/^-?\d+$/', $v)) {
                    throw new InvalidArgumentException("«{$label}» لازم يكون رقم صحيح.");
                }
                $n = (int) $v;
                self::range($label, (float) $n, $d);
                return (string) $n;

            case self::T_FLOAT:
            case self::T_MONEY:
                if ($v === '') { return ''; }
                $v = str_replace([',', '٬', ' '], '', $v);
                if (!is_numeric($v)) {
                    throw new InvalidArgumentException("«{$label}» لازم يكون رقم.");
                }
                $n = (float) $v;
                self::range($label, $n, $d);
                return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') ?: '0';

            case self::T_RATE:
                if ($v === '') { return ''; }
                $v = str_replace([',', '٬', ' '], '', $v);
                if (!is_numeric($v)) {
                    throw new InvalidArgumentException("«{$label}» لازم يكون رقم.");
                }
                $n = (float) $v;
                // ⚠️ نفس تسامح `saveGeneral` بالحرف: 14 معناها 0.14.
                if ($n > 1) { $n = $n / 100; }
                self::range($label, $n, $d);
                return (string) $n;

            case self::T_DATE:
                if ($v === '') { return ''; }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                    throw new InvalidArgumentException("«{$label}» لازم يكون تاريخ بصيغة سنة-شهر-يوم.");
                }
                [$y, $m, $dd] = array_map('intval', explode('-', $v));
                if (!checkdate($m, $dd, $y)) {
                    throw new InvalidArgumentException("«{$label}» تاريخ مش موجود.");
                }
                return $v;

            case self::T_ENUM:
                if (!in_array($v, array_map('strval', $d['options']), true)) {
                    throw new InvalidArgumentException("قيمة «{$label}» مش من القيم المسموحة.");
                }
                return $v;

            case self::T_JSON:
                if ($v === '') { return ''; }
                json_decode($v, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new InvalidArgumentException("«{$label}» مش JSON صالح.");
                }
                return $v;
        }

        throw new InvalidArgumentException("نوع الإعداد «{$label}» مش معروف.");
    }

    /** @throws InvalidArgumentException */
    private static function range(string $label, float $n, array $d): void
    {
        if ($d['min'] !== null && $n < (float) $d['min']) {
            throw new InvalidArgumentException("«{$label}» أقل من الحد الأدنى ({$d['min']}).");
        }
        if ($d['max'] !== null && $n > (float) $d['max']) {
            throw new InvalidArgumentException("«{$label}» أكبر من الحد الأقصى ({$d['max']}).");
        }
    }
}
