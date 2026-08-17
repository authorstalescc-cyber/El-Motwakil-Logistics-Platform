<?php
namespace App\Models;

use App\Core\App;
use App\Core\Auth;
use App\Core\Database;

/**
 * عهدة المناديب (v35) — محفظة الفلوس النازلة للمندوب عشان يدفع بيها إيصالات الأذونات.
 *
 *      رصيد عهدة المندوب = Σ التنزيلات − Σ البنود المعتمدة المدفوعة منها − Σ المرتجعات
 *
 * ⚠️ **v42 غيّر علاقة المحفظة دي بالخزنة (قرار المالك — عكس v35).**
 *
 *    v35 كان بينسخ نموذج كروت الفيزا: لا التنزيل ولا الصرف بيخصم من الخزنة.
 *    ودي كانت **غلطة**: كارت الفيزا بنك (فلوس تانية خالص)، لكن عهدة المندوب
 *    بتطلع كاش من الدرج بإيد المالك — فالخزنة كانت بتفضل مضخّمة بقيمة اللي
 *    في جيوب المناديب، والإيصالات كانت بتختفي من تقفيل يومها.
 *
 *    القاعدة الجديدة (الدورة الكاملة بتخصم بالظبط اللي اتصرف فعلاً):
 *      • «تنزيل عهدة» (rep_custody_deposits) → **مالوش أثر على الخزنة** (تحويل داخلي).
 *      • البند الموسوم permit_stages.paid_by_rep_id → **بيتخصم من الخزنة يوم صرفه**
 *        زي أي بند عادي، وبيدخل التقفيل اليومي ومطابقة v28.
 *      • نثريات المناديب (rep_misc_expenses) → نفس الكلام، بتظهر في الصف العام.
 *      • «مرتجع العهدة» → **مالوش أثر على الخزنة** (الفلوس ماخرجتش وقت التنزيل،
 *        فإضافتها تاني كانت هتبقى تحسيبًا مزدوجًا — الخيار اتشال من التسوية).
 *
 *    المحفظة نفسها **فضلت منفصلة زي ما هي**: رصيد كل مندوب بيتحسب هنا لوحده،
 *    والخزنة مش بتعرف عنه حاجة. اللي اتغيّر بس هو وقت الخصم من الخزنة.
 *
 * ⚠️ عشان كده **مفيش excludeSql بعد v42** — أي حساب للخزنة بيعدّ بنود المناديب
 *    زي غيرها، فمفيش نقطة مزامنة ممكن تتنسي. لو لقيت كود بيستثني paid_by_rep_id
 *    من حساب خزنة، ده بقايا v35 ولازم يتشال.
 *
 * ملاحظة: الموديل ده **لا يمسّ وحدة المناديب القديمة (Rep/v21)** — المرتبات
 * والمكافآت والخصومات سجل منفصل تمامًا جنبه.
 */
class RepCustody
{
    /** هل عمود الوسم موجود؟ (المصدر الوحيد لفحص جاهزية v35 في الحسابات) */
    public static function columnReady(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $ok = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'permit_stages'
                   AND column_name = 'paid_by_rep_id'"
            );
        }
        return $ok;
    }

    /** هل جداول عهدة المناديب موجودة؟ (يخلّي النظام يشتغل حتى قبل migrate_v35) */
    public static function available(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $row = Database::row(
                "SELECT
                    EXISTS(SELECT 1 FROM information_schema.columns
                           WHERE table_schema=DATABASE() AND table_name='permit_stages'
                             AND column_name='paid_by_rep_id') AS has_column,
                    EXISTS(SELECT 1 FROM information_schema.tables
                           WHERE table_schema=DATABASE() AND table_name='rep_custody_deposits') AS has_table"
            ) ?: [];
            $ok = !empty($row['has_column']) && !empty($row['has_table']);
        }
        return $ok;
    }

    // ─────────────────────────────────────────────── خط الفصل (بداية النظام الجديد)

    /** كاش تاريخ الفصل داخل الطلب. '' = الميزة مطفية (سلوك قديم بالحرف). */
    private static ?string $epoch = null;

    /**
     * تاريخ بداية عهدة المناديب في النظام الجديد (إعداد `rep_custody_epoch`).
     *
     * ⚠️ **السبب:** الفلوس قبل التاريخ ده كانت متسجّلة بنموذج غلط — عهدة السائق
     *    كانت بتتخصم من خزنة المكتب والمندوب هو اللي دفعها، فرصيد كل مندوب
     *    متضخّم بمقدار اللي سلّمه للسواقين (مقيس: ~690,500 على مستوى المناديب).
     *    بدل ما نمسح أو نعدّل صفوفًا قديمة (وده بيضيّع تاريخ الشغل ويخالف قرار
     *    المالك «البنود ما تتلمسش إطلاقًا»)، بنبدأ العدّ من تاريخ معلوم.
     *
     * ⚠️ بترجّع '' لو الإعداد مش موجود أو شكله غلط ⇒ **السلوك القديم بالحرف**،
     *    فالنظام يشتغل عادي قبل التفعيل، والتراجع = مسح الإعداد وبس.
     */
    public static function epoch(): string
    {
        if (self::$epoch === null) {
            $v = trim((string) App::setting('rep_custody_epoch', ''));
            self::$epoch = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
        }
        return self::$epoch;
    }

    /**
     * ⚠️ **المصدر الوحيد لشرط «الحركة دي بعد بداية النظام الجديد؟»**
     *
     * بترجّع جزء SQL جاهز للّزق بعد شرط قائم، مثال:
     *     "... WHERE rep_id = ?" . RepCustody::epochSql('dep_date')
     *     "... WHERE 1" . RepCustody::epochSql('h.handover_date')
     *
     * ⚠️ **ممنوع تكتب شرط التاريخ ده بإيدك في أي مكان.** لازم يتنادى في **كل**
     *    نقطة بتحسب رصيد مندوب — تنزيلات · بنود · نثريات · تسليمات سائقين ·
     *    عهد بوالص · تسويات · تحويلات · بنود مستنية. نسيان موضع واحد = كارت
     *    بيقول رقم وكشف بيقول رقم تاني **بلا أي خطأ ظاهر**
     *    (نفس نمط `RepBillCustody::officeExcludeSql` و`RepMiscExpense::cashOnlySql`).
     *    `bin/verify_custody_epoch.php` بيفشل لو أي موضع اتنسي.
     *
     * ⚠️ **النطاق: محافظ المناديب بس.** ممنوع تستخدمها في `Closing` أو `Custody`
     *    (خزنة المكتب) — الخزنة مربوطة بـ`daily_closings` ويوم 2026-07-28 متقفل
     *    فعلاً، والقفل هو اللي بيجمّد كل اللي قبل خط الفصل.
     *
     * التاريخ متحقَّق من شكله في `epoch()` (YYYY-MM-DD حرفيًّا) فلزقه آمن.
     */
    public static function epochSql(string $col): string
    {
        $e = self::epoch();
        return $e === '' ? '' : " AND $col >= '$e'";
    }

    /**
     * ⚠️ بتصفّر كاش `epoch()`. **إلزامية في أي ترحيل بيكتب الإعداد** — الكاش
     *    بيتقرا قبل الكتابة (وقت قياس الأرصدة) فبيفضل بالقيمة القديمة بعدها،
     *    فتحقّق ما بعد الترحيل يفشل بإنذار كاذب (مصيدة v57 الموثَّقة).
     */
    public static function refreshEpoch(): void
    {
        self::$epoch = null;
        self::epoch();
    }

    // ───────────────────────── قاعدة الخزنة الجديدة (المرحلة ٢ من docs/16)

    /**
     * كاش جاهزية عمود `source`.
     * ⚠️ **خاصية ساكنة مش `static $x` جوّه الدالة** عن قصد — دي الطريقة الوحيدة
     *    اللي تخلّي `refreshSourceReady()` تقدر تصفّرها (مصيدة v57 الموثَّقة).
     */
    private static ?bool $sourceReady = null;

    /** هل عمود `rep_custody_deposits.source` موجود؟ (المصدر الوحيد لفحص v61) */
    public static function sourceReady(): bool
    {
        if (self::$sourceReady === null) {
            self::$sourceReady = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = 'rep_custody_deposits'
                    AND column_name = 'source'");
        }
        return self::$sourceReady;
    }

    /** ⚠️ إلزامية في `migrate_v61` — الكاش بيتقرا قبل الـALTER فيفضل false بعده. */
    public static function refreshSourceReady(): void
    {
        self::$sourceReady = null;
        self::sourceReady();
    }

    /** كاش تاريخ بداية قاعدة الخزنة الجديدة. '' = القاعدة مطفية (سلوك v42). */
    private static ?string $safeRuleFrom = null;

    /**
     * تاريخ بداية **قاعدة الخزنة الجديدة** (إعداد `rep_safe_rule_from`).
     *
     * ⚠️⚠️ **إعداد منفصل عن `rep_custody_epoch` عن قصد — والسبب مصيدة نشر حقيقية.**
     *    خط الفصل مفعّل على الإنتاج من 2026-07-30، فلو ربطنا القاعدة الجديدة بيه
     *    كان **رصيد الخزنة هيتغيّر لحظة وصول الملفات** — قبل الترحيل وقبل أي
     *    مراجعة. ولو ربطناها بوجود عمود `source` كان الترحيل نفسه هو اللي يقلب
     *    الفلوس، وتحقّق «الرصيد قبل = بعد» جوّه الترحيل يفشل.
     *    بالمفتاح المنفصل: **الرفع خامل · الترحيل خامل · التفعيل قرار صريح
     *    مراجَع، والتراجع = مسح إعداد واحد** (نفس نمط المرحلة ١ بالحرف).
     *
     * ⚠️ والسؤالان مختلفان فعلاً: `rep_custody_epoch` = «أنهي حركة تتحسب في رصيد
     *    المندوب»، و`rep_safe_rule_from` = «من إمتى الدرج بطّل يمتصّ صرف المندوب».
     *    بيتصادفوا على نفس التاريخ، بس ما ينفعش يبقوا مفتاحًا واحدًا.
     */
    public static function safeRuleFrom(): string
    {
        if (self::$safeRuleFrom === null) {
            $v = trim((string) App::setting('rep_safe_rule_from', ''));
            self::$safeRuleFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
        }
        return self::$safeRuleFrom;
    }

    /** ⚠️ إلزامية في أي سكربت بيكتب الإعداد (مصيدة v57 الموثَّقة). */
    public static function refreshSafeRule(): void
    {
        self::$safeRuleFrom = null;
        self::safeRuleFrom();
    }

    /**
     * ⚠️⚠️ **المصدر الوحيد لشرط «البند ده لسه بيتخصم من خزنة المكتب؟»** بعد
     *    قلب قاعدة v42 (المرحلة ٢).
     *
     * ═══ إيه اللي اتقلب وليه ═══
     * v42 كانت بتقول: تنزيل العهدة مالوش أثر على الخزنة، و**صرف المندوب بيتخصم
     * من الخزنة يوم صرفه**. ده كان صح **بس** طول ما كل تنزيل معاه توب-أب مرايا
     * في `manual_custody`. ولمّا الفلوس بقت تيجي من البنك للمندوب على طول (وده
     * الواقع الفعلي)، الخزنة بقت تتخصم لفلوس **عمرها ما دخلت الدرج** فبتنزل
     * سالب. القاعدة الجديدة: **المندوب محفظة مستقلة، وصرفه ما بيمسّ الدرج**، واللي
     * بيمسّ الدرج هو التنزيل **لو طلع منه فعلاً** (`source='office'`).
     *
     * ⚠️⚠️ **الشرط محكوم بخط الفصل عن قصد، والحدّ على عمود التاريخ بتاع الصف
     *    نفسه — وده اللي بيخلّي الأيام المتقفلة تفضل صح تلقائيًّا.**
     *    `Closing::refreshLockedChainFrom()` بتعيد حساب أي يوم متقفل من الصفوف
     *    الخام (وبتتنفّذ لوحدها مع «الدخول الحر»)، فلو الحدّ كان على «النهاردة»
     *    أو على إعداد عام، إعادة الحساب كانت هتطبّق القاعدة الجديدة على أيام
     *    قديمة و**تعيد كتابة تاريخ مقفول بالسكوت**. بالشكل ده الصف القديم بيفضل
     *    داخل الحساب دايمًا والجديد بره دايمًا، مهما اتعاد الحساب كام مرة.
     *
     * ⚠️ بترجّع `''` لو خط الفصل مطفي ⇒ **سلوك v42 بالحرف**، فالكود ينفع يترفع
     *    قبل التفعيل والتراجع = مسح إعداد واحد.
     *
     * @param string $dateCol عمود تاريخ الصف (`s.entry_date` · `exp_date` …)
     * @param string $repCol  عمود المندوب لو الجدول بيخلط بنود المكتب والمندوب
     *                        (`s.paid_by_rep_id`)، أو '' لو **كل** صفوفه للمندوب.
     */
    public static function safeChargeSql(string $dateCol, string $repCol = ''): string
    {
        if (!self::newSafeRuleActive()) { return ''; }
        $e = self::safeRuleFrom();
        return $repCol === ''
            ? " AND $dateCol < '$e'"
            : " AND ($repCol IS NULL OR $dateCol < '$e')";
    }

    /**
     * ⚠️ **الاتجاه التاني للقاعدة الجديدة** — شرط «التنزيل ده طلع من درج المكتب
     *    فعلاً»، للجمع كمصروف في `Closing`.
     *
     * ⚠️⚠️ **الاتجاهان لازم يمشوا مع بعض**: `safeChargeSql` بتشيل صرف المندوب من
     *    الخزنة، ودي بتضيف التنزيل المكتبي. تطبيق الأولى بلا التانية = الخزنة
     *    بتقول رقمًا أعلى من الحقيقة لأن الكاش اللي طلع من الدرج مش متسجّل خروجه.
     *
     * بترجّع `''` لو العمود مش موجود أو خط الفصل مطفي ⇒ صفر أثر (السلوك القديم).
     */
    public static function officeDepositSql(string $dateCol = 'dep_date'): string
    {
        if (!self::newSafeRuleActive()) { return ''; }
        return " AND source = 'office' AND $dateCol >= '" . self::safeRuleFrom() . "'";
    }

    // ─────────────── «الخزنة = كل كاش الشركة» (قرار المالك 2026-07-30)

    /** كاش تاريخ بداية اعتبار عهدة المندوب إضافة للخزنة. '' = مطفي. */
    private static ?string $bankTopupFrom = null;

    /**
     * تاريخ بداية قاعدة **«أي عهدة تنزل للمندوب تتحسب إضافة للخزنة»**
     * (إعداد `rep_bank_topup_from` — قرار المالك الحرفي: «أي عهدة يتم إضافتها
     * للمندوب كأنها في الدرج»).
     *
     * ═══ الفرق بين النموذجين ═══
     *   • **الخزنة = الدرج بس** (المرحلة ٢): التنزيل مالوش أثر، وصرف المندوب
     *     مالوش أثر. الرقم = الكاش اللي في درج المكتب وتقدر تعدّه بإيدك.
     *   • **الخزنة = كل كاش الشركة** (ده): التنزيل من البنك **بيزوّد** الخزنة،
     *     وصرف المندوب **بيخصم** منها. الرقم = كل الكاش برّه البنك (درج +
     *     جيوب المناديب)، وتفكيكه بيبان في قسم «الكاش برّه البنك» تحت الشاشة.
     *
     * ⚠️⚠️ **`carry` و`office` عمرهم ما بيزوّدوا الخزنة — ودي مش تفصيلة:**
     *   • `office` = كاش اتنقل **من الدرج لجيب المندوب**. الاتنين جوّه الرقم،
     *     فالإجمالي ما اتغيّرش ⇒ تحويل داخلي بلا أثر.
     *   • `carry` = رصيد كان مع المندوب **من قبل**، وهو متحسوب خلاص في المرحّل
     *     بتاع اليوم السابق. لو زوّدناه تاني بيتعدّ **مرتين**.
     *   يعني **`bank` بس** هي الفلوس الجديدة الداخلة للشركة.
     *
     * ⚠️ إعداد منفصل عشان الرفع يفضل خامل والتفعيل قرار مراجَع (نفس نمط
     *    `rep_safe_rule_from` و`rep_custody_epoch`).
     */
    public static function bankTopupFrom(): string
    {
        if (self::$bankTopupFrom === null) {
            $v = trim((string) App::setting('rep_bank_topup_from', ''));
            self::$bankTopupFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
        }
        return self::$bankTopupFrom;
    }

    /** ⚠️ إلزامية في أي سكربت بيكتب الإعداد (مصيدة v57 الموثَّقة). */
    public static function refreshBankTopup(): void
    {
        self::$bankTopupFrom = null;
        self::bankTopupFrom();
    }

    /** هل قاعدة «عهدة المندوب إضافة للخزنة» فعّالة؟ */
    public static function bankTopupActive(): bool
    {
        return self::bankTopupFrom() !== '' && self::sourceReady();
    }

    /**
     * ⚠️ **المصدر الوحيد لشرط «التنزيل ده إضافة للخزنة؟»** — للجمع في
     *    `Closing::sumTopups`. `bank` بس، ومن تاريخ التفعيل ورايح.
     *
     * بترجّع `''` لو القاعدة مطفية ⇒ صفر أثر (السلوك السابق بالحرف).
     */
    public static function bankTopupSql(string $dateCol = 'dep_date'): string
    {
        if (!self::bankTopupActive()) { return ''; }
        /* ⚠️ `company` (v67) معاها زي `bank` بالحرف: الاتنين فلوس داخلة جيب
           المندوب **من برّه الدرج**، فلازم يزوّدوا الإجمالي في النموذج المجمّع.
           لو `company` اتنست هنا، الفلوس بتتخصم من خزنة الشركة وما تظهرش في أي
           خزنة تانية ⇒ **بتتبخّر** (نفس مصيدة الفيزا الموثَّقة في v45). */
        $in = "'" . implode("','", self::TOPUP_SOURCES) . "'";
        return " AND source IN ($in) AND $dateCol >= '" . self::bankTopupFrom() . "'";
    }

    /**
     * هل قاعدة الخزنة الجديدة فعّالة؟ — **إعداد التفعيل + عمود المصدر مع بعض.**
     *
     * ⚠️ الشرطان مربوطان عن قصد: القاعدة بتشيل صرف المندوب من الخزنة **و**بتضيف
     *    التنزيل المكتبي. بلا عمود `source` مفيش طريقة نعرف بيها أي تنزيل طلع من
     *    الدرج، فتفعيل النص الأول لوحده = الخزنة بتقول رقمًا أعلى من الحقيقة.
     */
    public static function newSafeRuleActive(): bool
    {
        return self::safeRuleFrom() !== '' && self::sourceReady();
    }

    /**
     * أرشيف ما **قبل** خط الفصل لمندوب — قراءة فقط، للعرض وبس.
     *
     * ⚠️ **ليه موجودة:** أول ما نفعّل خط الفصل، كل حركة قبله بتختفي من الكارت.
     *    من غير القسم ده المالك بيفتح كارت مندوب وياخد انطباع إن **البيانات
     *    اتمسحت** — وهي موجودة بالكامل، بس مستبعدة من الحساب عن قصد. القسم
     *    بيقول الرقم ويشرح سببه، فالاختفاء يبقى مفهومًا بدل ما يبقى مقلقًا.
     *
     * ⚠️ **بتكتب شرط التاريخ بإيدها عن قصد — وهي الاستثناء الوحيد المسموح.**
     *    `epochSql` بترجّع «من خط الفصل ورايح»، وإحنا عايزين **العكس** بالظبط.
     *    ولإنها عرض بحت ما بتدخلش أي رصيد، فما فيش خطر تزامن.
     *
     * @return array|null null لو الميزة مطفية (مفيش أرشيف أصلاً).
     */
    public static function archiveBefore(int $repId): ?array
    {
        $e = self::epoch();
        if ($e === '' || !self::available()) { return null; }
        $one = static fn (string $sql, array $a): float
            => round((float) Database::scalar($sql, $a), 2);

        $dep = $one("SELECT COALESCE(SUM(amount),0) FROM rep_custody_deposits
                     WHERE rep_id = ? AND dep_date < ?", [$repId, $e]);
        $spent = $one("SELECT COALESCE(SUM(amount),0) FROM permit_stages
                       WHERE paid_by_rep_id = ? AND " . Permit::cashOutSql('') . "
                         AND entry_date < ?", [$repId, $e]);
        $misc = $one("SELECT COALESCE(SUM(amount),0) FROM rep_misc_expenses
                      WHERE rep_id = ? AND " . RepMiscExpense::cashOnlySql() . "
                        AND exp_date < ?", [$repId, $e]);
        $ret = $one("SELECT COALESCE(SUM(returned),0) FROM rep_custody_settlements
                     WHERE rep_id = ? AND settle_date < ?", [$repId, $e]);
        $tin = $one("SELECT COALESCE(SUM(amount),0) FROM rep_custody_transfers
                     WHERE to_rep_id = ? AND transfer_date < ?", [$repId, $e]);
        $tout = $one("SELECT COALESCE(SUM(amount),0) FROM rep_custody_transfers
                      WHERE from_rep_id = ? AND transfer_date < ?", [$repId, $e]);
        $pend = $one("SELECT COALESCE(SUM(amount),0) FROM rep_submissions
                      WHERE rep_id = ? AND status = 'pending' AND " . RepPortal::pendingCashSql() . "
                        AND entry_date < ?", [$repId, $e]);
        $pendCnt = (int) Database::scalar("SELECT COUNT(*) FROM rep_submissions
                      WHERE rep_id = ? AND status = 'pending' AND entry_date < ?", [$repId, $e]);

        $spentAll = round($spent + $misc, 2);
        return [
            'epoch'       => $e,
            'deposits'    => $dep,
            'spent'       => $spentAll,
            'returned'    => $ret,
            'transfer_in' => $tin,
            'transfer_out' => $tout,
            'balance'     => round($dep + $tin - $spentAll - $ret - $tout, 2),
            'pending'     => $pend,
            'pending_cnt' => $pendCnt,
            'any'         => ($dep || $spentAll || $ret || $tin || $tout || $pendCnt) ? true : false,
        ];
    }

    // ─────────────────────────────────────────────── الرصيد

    /**
     * ⚠️ v49 — **عهدة اليوم**: كل يوم عمل له عهدة مستقلة تمامًا عن اللي قبله وبعده.
     *
     *     عهدة يوم D = المنزَّل في D + المحوَّل له في D
     *                 − المصروف في D − المرتجع في D − المحوَّل منه في D
     *
     * **مفيش ترحيل** (قرار المالك): الفاضل من إمبارح ما بيدخلش عهدة النهاردة.
     * كل دوال الرصيد تحت بتاخد `$day` اختياري: `null` = الرصيد **التراكمي** (كل
     * الأيام) وده السلوك القديم بالحرف، و«يوم» = عهدة اليوم ده لوحده.
     *
     * ⚠️ التراكمي **ما اتلغاش ولا اتغيّر**: هو «الفلوس اللي برّه مع المندوب فعلاً»
     *    وبتقراه التسويات وحد التحويل والكارت والداشبورد. العهدة اليومية عدسة
     *    فوقه مش بديل عنه — ولو خلّيت أي حساب تراكمي يقرا يومًا، الفلوس تضيع.
     *
     * شرط اليوم بيتكتب في مكان واحد لكل مصدر (هنا + الموديل صاحب الجدول) عشان
     * ما يبقاش فيه تعريفان للصرف ممكن يختلفوا.
     */
    private static function dayClause(string $col, ?string $day, array &$args): string
    {
        // ⚠️ خط الفصل بيتلزق **دايمًا**، سواء طلبنا يومًا واحد أو التراكمي:
        //    التراكمي لازم يبدأ من بداية النظام الجديد، واليوم الواحد لازم يرفض
        //    أي تاريخ قبله بدل ما يعرض أرقام العهد القديمة الغلط.
        $epoch = self::epochSql($col);
        if ($day === null || $day === '') { return $epoch; }
        $args[] = $day;
        return " AND $col = ?" . $epoch;
    }

    /** إجمالي المنزَّل للمندوب ($day = يوم واحد، null = كل الأيام). */
    public static function depositsTotal(int $repId, ?string $day = null): float
    {
        if (!self::available()) { return 0.0; }
        $args = [$repId];
        $w = self::dayClause('dep_date', $day, $args);
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM rep_custody_deposits WHERE rep_id = ?$w", $args), 2);
    }

    /**
     * إجمالي اللي صرفه المندوب فعلاً **من كاش عهدته**.
     * ٤ مصادر، كلها فلوسها خرجت من نفس العهدة:
     *   • بنود معتمدة على أذونات (permit_stages.paid_by_rep_id) — v35
     *   • نثريات معتمدة بلا إذن (rep_misc_expenses) — v39
     *   • تسليمات عهدة لسائقين (rep_driver_handovers) — v45
     *   • عهدة سائق على بوليصة أضافها بنفسه (bawalis.custody_rep_id) — v60
     *
     * ⚠️ **v45 — المدفوع بالفيزا مستثنى** (`is_visa = 1`): البند ده خرج من كارت
     *    المكتب مش من جيب المندوب، فبيتخصم من رصيد الكارت وبس. لو اتحسب هنا كمان
     *    كان هيبقى تحسيب مزدوج ورصيد المندوب هيقلّ غلط.
     *
     * ⚠️ تحذير تزامن: الـ٤ مصادر دي لازم يكونوا مع بعض في `spentTotal` و`cards`
     *    و`totalOutstanding` و`spending` و`dayLedger` و`dayTotalsByRep`
     *    و`dashboard`. لو واحد اتنسي، الكارت هيقول رقم والكشف يقول رقم تاني.
     */
    public static function spentTotal(int $repId, ?string $day = null): float
    {
        if (!self::available()) { return 0.0; }
        $args = [$repId];
        $w = self::dayClause('entry_date', $day, $args);
        $stages = (float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM permit_stages
             WHERE paid_by_rep_id = ? AND " . Permit::cashOutSql('') . "$w", $args);
        return round(
            $stages + RepMiscExpense::totalFor($repId, $day) + RepDriverHandover::totalFor($repId, $day)
            + RepBillCustody::totalFor($repId, $day), 2);
    }

    /** إجمالي اللي رجّعه المندوب في التسويات ($day = يوم واحد، null = كل الأيام). */
    public static function returnedTotal(int $repId, ?string $day = null): float
    {
        if (!self::available()) { return 0.0; }
        $args = [$repId];
        $w = self::dayClause('settle_date', $day, $args);
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(returned),0) FROM rep_custody_settlements WHERE rep_id = ?$w", $args), 2);
    }

    /**
     * صافي التحويلات بين المناديب (v46): المستلَم − المحوَّل.
     * موجب = المندوب استلم أكتر مما بعت، فبيتزاد لرصيده؛ والعكس بيتخصم.
     * بيرجع 0 قبل migrate_v46 فالحساب يفضل زي ما كان.
     */
    public static function transferNet(int $repId, ?string $day = null): float
    {
        if (!RepTransfer::available()) { return 0.0; }
        return round(RepTransfer::inTotal($repId, $day) - RepTransfer::outTotal($repId, $day), 2);
    }

    /**
     * رصيد عهدة المندوب = المنزَّل − المصروف − المرتجع + صافي التحويلات.
     * صافي التحويلات (v46): اللي استلمه من زملائه ناقص اللي حوّله لهم.
     *
     * $day (v49): عهدة يوم واحد بلا أي ترحيل من قبله. null = التراكمي (الفلوس
     * اللي برّه مع المندوب فعلاً) — ده اللي بتقراه التسوية وحد التحويل والكارت.
     */
    public static function balance(int $repId, ?string $day = null): float
    {
        return round(
            self::depositsTotal($repId, $day) - self::spentTotal($repId, $day)
            - self::returnedTotal($repId, $day) + self::transferNet($repId, $day), 2);
    }

    /** تفكيك الرصيد لكارت المندوب: deposits, spent, returned, transfer_net, balance. */
    public static function breakdown(int $repId, ?string $day = null): array
    {
        $dep = self::depositsTotal($repId, $day);
        $spent = self::spentTotal($repId, $day);
        $ret = self::returnedTotal($repId, $day);
        $tnet = self::transferNet($repId, $day);
        return [
            'day'          => $day,
            'deposits'     => $dep,
            'spent'        => $spent,
            'returned'     => $ret,
            'transfer_in'  => RepTransfer::available() ? RepTransfer::inTotal($repId, $day) : 0.0,
            'transfer_out' => RepTransfer::available() ? RepTransfer::outTotal($repId, $day) : 0.0,
            'transfer_net' => $tnet,
            'balance'      => round($dep - $spent - $ret + $tnet, 2),
        ];
    }

    // ─────────────────────────────────────────────── عهدة اليوم (v49)

    /**
     * سبب منع الكتابة على يوم، أو null لو اليوم مفتوح.
     *
     * ⚠️ المصدر الوحيد للفحص ده — `Closing::guard` نفسها اللي بتحرس كل كتابة
     *    مؤرَّخة في النظام (v18)، فمستحيل يوم يبقى متقفل للمكتب ومفتوح للمندوب.
     *    وبتحترم «الدخول الحر» للمالك تلقائيًّا (v27) بلا أي شرط مكتوب هنا.
     */
    public static function dayLockReason(string $day): ?string
    {
        if (Closing::guard($day) === null) { return null; }
        return 'يوم ' . dfmt($day) . ' اتقفل في المكتب — مش هينفع تسجّل ولا تعدّل عليه. كلّم المكتب يفتحه.';
    }

    /** هل اليوم مقفول للكتابة؟ (اختصار عرضي لـ`dayLockReason`) */
    public static function isDayLocked(string $day): bool
    {
        return self::dayLockReason($day) !== null;
    }

    /**
     * كشف «عهدة كل يوم» لمندوب — صف لكل يوم فيه حركة، الأحدث أولاً.
     *
     * كل صف مستقل بذاته: **مفيش عمود «مرحّل»** لأن مفيش ترحيل (قرار المالك).
     * `balance` سالب = المندوب صرف في اليوم ده أكتر من اللي نزل له فيه (مسموح،
     * بيتعرض كتنبيه بس — الفرق بيتغطّى من فلوس أيام تانية أو من جيبه).
     *
     * قراءة فقط، ومن نفس مصادر الحقيقة بتاعة الرصيد التراكمي بالظبط.
     *
     * @return array<int,array{day:string,deposits:float,spent:float,returned:float,
     *                         transfer_in:float,transfer_out:float,pending:float,
     *                         balance:float,locked:bool}>
     */
    public static function dayLedger(int $repId, int $limit = 90): array
    {
        if (!self::available()) { return []; }

        $days = [];
        $add = static function (array $map, string $key) use (&$days): void {
            foreach ($map as $d => $v) {
                $d = (string) $d;
                if ($d === '') { continue; }
                if (!isset($days[$d])) {
                    $days[$d] = ['day' => $d, 'deposits' => 0.0, 'spent' => 0.0, 'returned' => 0.0,
                                 'transfer_in' => 0.0, 'transfer_out' => 0.0, 'pending' => 0.0];
                }
                $days[$d][$key] = round($days[$d][$key] + (float) $v, 2);
            }
        };
        $pairs = static function (array $rows): array {
            $out = [];
            foreach ($rows as $r) { $out[(string) $r['d']] = (float) $r['total']; }
            return $out;
        };

        $add($pairs(Database::all(
            "SELECT dep_date d, SUM(amount) total FROM rep_custody_deposits
             WHERE rep_id = ?" . self::epochSql('dep_date') . " GROUP BY dep_date", [$repId])), 'deposits');

        $add($pairs(Database::all(
            "SELECT entry_date d, SUM(amount) total FROM permit_stages
             WHERE paid_by_rep_id = ? AND " . Permit::cashOutSql('')
             . self::epochSql('entry_date') . " GROUP BY entry_date", [$repId])), 'spent');
        $add(RepMiscExpense::totalsByDay($repId), 'spent');
        $add(RepDriverHandover::totalsByDay($repId), 'spent');
        $add(RepBillCustody::totalsByDay($repId), 'spent');   // v60 — عهدة بوليصة من محفظته

        $add($pairs(Database::all(
            "SELECT settle_date d, SUM(returned) total FROM rep_custody_settlements
             WHERE rep_id = ?" . self::epochSql('settle_date') . " GROUP BY settle_date", [$repId])), 'returned');

        $add(RepTransfer::inTotalsByDay($repId), 'transfer_in');
        $add(RepTransfer::outTotalsByDay($repId), 'transfer_out');

        // المستنّي اعتماده كاش: خرج من إيد المندوب فعلاً في اليوم ده وإن كان
        // لسه ما اتحسبش في `spent` (بيتحسب وقت الاعتماد). عشان كده الشاشات
        // بتعرض `pocket` (= balance − pending) كـ«الفاضل معاه»، و`balance`
        // بيفضل الرصيد المحاسبي بعد المعتمد بس. الفيزا و«غير مدفوع» معلوماتيان
        // ولا يدخلان `pending` لأنهما لم يخرجا من عهدة المندوب.
        $add($pairs(Database::all(
            "SELECT entry_date d, SUM(amount) total FROM rep_submissions
             WHERE rep_id = ? AND status = 'pending' AND " . RepPortal::pendingCashSql()
             . self::epochSql('entry_date') . "
             GROUP BY entry_date", [$repId])), 'pending');

        krsort($days, SORT_STRING);
        $out = [];
        foreach (array_slice($days, 0, max(1, $limit), true) as $row) {
            $row['balance'] = round(
                $row['deposits'] + $row['transfer_in']
                - $row['spent'] - $row['returned'] - $row['transfer_out'], 2);
            $row['pocket'] = round($row['balance'] - $row['pending'], 2);
            $row['locked'] = self::isDayLocked($row['day']);
            $out[] = $row;
        }
        return $out;
    }

    /**
     * **حكم عهدة المندوب في اليوم** — «سوّى ولا ما سواش؟» (طلب المالك 2026-08-17).
     *
     * ⚠️⚠️ **المصدر الوحيد للحكم — ممنوع تكتب الشرط بإيدك في أي شاشة.**
     *    الشاشة كانت بتعرض `نزل 5000 · صرف 4200` وتسيب المالك يطرح في دماغه
     *    عشان يعرف إن فيه ٨٠٠ لسه برّه. الحكم بقى مكتوب مرة واحدة هنا،
     *    فالكارت والقايمة ونافذة التفاصيل بيقولوا **نفس** الكلمة بالظبط.
     *
     * الترتيب مقصود — **الأخطر الأول**:
     *   ① `pending` > 0  ← بند دفعه المندوب ومستنّي **قرارك**؛ ده تصرّف مطلوب
     *      منك مش منه، فبيغلب على أي حاجة تانية.
     *   ② `balance` > 0  ← لسه ماسك كاش مش راجع.
     *   ③ `balance` < 0  ← فرش من جيبه، المكتب مدين له.
     *   ④ الباقي        ← سوّى (نزل = صرف + رجّع).
     *
     * ⚠️ **الرصيد السالب مش صفر ومش خطأ** — معناه المندوب دفع من جيبه فوق
     *    عهدته، والمكتب لازم يرجّع له (قاعدة docs/16 §10.5: ممنوع القص على صفر).
     *
     * ⚠️ **المندوب اللي مالوش أي حركة في اليوم ما بيوصلش هنا أصلاً** —
     *    `dayTotalsByRep()` بترجّع اللي عليهم حركة بس، والصفر مش محفظة.
     *
     * @param array $row صف من `dayTotalsByRep()` (deposits · spent · returned · balance · pending)
     * @return array{key:string,text:string,tone:string,amount:float}
     */
    public static function dayVerdict(array $row): array
    {
        $pending = round((float) ($row['pending'] ?? 0), 2);
        $balance = round((float) ($row['balance'] ?? 0), 2);
        $pocket  = round((float) ($row['pocket'] ?? ($balance - $pending)), 2);

        if ($pending > 0.005) {
            return ['key' => 'pending', 'tone' => 'red', 'amount' => $pending,
                    'text' => 'مستني اعتمادك'];
        }
        if ($balance > 0.005) {
            return ['key' => 'holding', 'tone' => 'gold', 'amount' => $pocket,
                    'text' => 'لسه معاه'];
        }
        if ($balance < -0.005) {
            return ['key' => 'owed', 'tone' => 'navy', 'amount' => abs($balance),
                    'text' => 'له عند المكتب'];
        }
        return ['key' => 'settled', 'tone' => 'green', 'amount' => 0.0, 'text' => 'سوّى عهدته'];
    }

    /**
     * عهدة يوم واحد لكل المناديب — [rep_id => تفكيك اليوم] (للداشبورد والكروت).
     * استعلام مجمَّع لكل مصدر بدل استعلام لكل مندوب.
     */
    public static function dayTotalsByRep(string $day): array
    {
        if (!self::available() || $day === '') { return []; }
        $out = [];
        $seed = static function (int $rid) use (&$out): void {
            if (!isset($out[$rid])) {
                $out[$rid] = ['deposits' => 0.0, 'spent' => 0.0, 'returned' => 0.0,
                              'transfer_in' => 0.0, 'transfer_out' => 0.0, 'pending' => 0.0];
            }
        };
        $merge = static function (array $map, string $key) use (&$out, $seed): void {
            foreach ($map as $rid => $v) {
                $rid = (int) $rid;
                $seed($rid);
                $out[$rid][$key] = round($out[$rid][$key] + (float) $v, 2);
            }
        };
        $byRep = static function (string $sql) use ($day): array {
            $m = [];
            foreach (Database::all($sql, [$day]) as $r) { $m[(int) $r['rid']] = (float) $r['total']; }
            return $m;
        };

        $merge($byRep("SELECT rep_id rid, SUM(amount) total FROM rep_custody_deposits
                       WHERE dep_date = ?" . self::epochSql('dep_date') . " GROUP BY rep_id"), 'deposits');
        $merge($byRep("SELECT paid_by_rep_id rid, SUM(amount) total FROM permit_stages
                       WHERE paid_by_rep_id IS NOT NULL AND " . Permit::cashOutSql('') . " AND entry_date = ?"
                       . self::epochSql('entry_date') . "
                       GROUP BY paid_by_rep_id"), 'spent');
        $merge(RepMiscExpense::totalsByRep($day), 'spent');
        $merge(RepDriverHandover::totalsByRep($day), 'spent');
        $merge(RepBillCustody::totalsByRep($day), 'spent');   // v60
        $merge($byRep("SELECT rep_id rid, SUM(returned) total FROM rep_custody_settlements
                       WHERE settle_date = ?" . self::epochSql('settle_date') . " GROUP BY rep_id"), 'returned');
        $merge(RepTransfer::inTotalsByRep($day), 'transfer_in');
        $merge(RepTransfer::outTotalsByRep($day), 'transfer_out');
        $merge($byRep("SELECT rep_id rid, SUM(amount) total FROM rep_submissions
                       WHERE status = 'pending' AND " . RepPortal::pendingCashSql()
                       . " AND entry_date = ?"
                       . self::epochSql('entry_date') . "
                       GROUP BY rep_id"), 'pending');

        foreach ($out as $rid => $row) {
            $out[$rid]['balance'] = round(
                $row['deposits'] + $row['transfer_in']
                - $row['spent'] - $row['returned'] - $row['transfer_out'], 2);
            $out[$rid]['pocket'] = round($out[$rid]['balance'] - $row['pending'], 2);
        }
        return $out;
    }

    /**
     * تفاصيل يوم واحد **عبر كل المناديب** — لنوافذ التفاصيل (drill-down).
     *
     * ⚠️ **مفيش استعلام جديد ولا تعريف تاني.** بتلفّ على المناديب اللي عليهم
     *    حركة في اليوم (من `dayTotalsByRep`) وتنادي `spending($rid, $day)`
     *    نفسها — فمستحيل النافذة تقول رقمًا والكشف يقول غيره. عددهم في اليوم
     *    الواحد بيبقى أفراد، فالتكلفة مهملة.
     *
     * @return array<int,array> صفوف الصرف + مفتاح `rep_name` لكل صف
     */
    public static function daySpending(string $day): array
    {
        if (!self::available() || $day === '') { return []; }
        $names = [];
        foreach (Database::all("SELECT id, name FROM reps") as $r) { $names[(int) $r['id']] = (string) $r['name']; }

        $out = [];
        foreach (self::dayTotalsByRep($day) as $rid => $row) {
            if (($row['spent'] ?? 0) <= 0) { continue; }
            foreach (self::spending((int) $rid, $day) as $s) {
                if (!empty($s['is_visa']) || !empty($s['unpaid'])) { continue; }
                $s['rep_id'] = (int) $rid;
                $s['rep_name'] = $names[(int) $rid] ?? '';
                $out[] = $s;
            }
        }
        usort($out, static fn ($a, $b) => (float) $b['amount'] <=> (float) $a['amount']);
        return $out;
    }

    /** تنزيلات العهدة في يوم واحد عبر كل المناديب (لنافذة التفاصيل). */
    public static function dayDeposits(string $day): array
    {
        if (!self::available() || $day === '') { return []; }
        return Database::all(
            "SELECT d.*, r.name AS rep_name
             FROM rep_custody_deposits d
             JOIN reps r ON r.id = d.rep_id
             WHERE d.dep_date = ?" . self::epochSql('d.dep_date') . "
             ORDER BY d.amount DESC, d.id DESC", [$day]);
    }

    /**
     * كل المناديب + رصيد عهدة كل واحد (لصفحة الكروت).
     * $onlyActive = المناديب النشطين بس.
     */
    public static function cards(bool $onlyActive = false, string $q = ''): array
    {
        if (!self::available()) { return []; }
        $where = []; $args = [];
        if ($onlyActive) { $where[] = "r.status = 'active'"; }
        if ($q !== '')   { $where[] = "(r.name LIKE ? OR r.phone LIKE ?)"; $args[] = "%$q%"; $args[] = "%$q%"; }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $rows = Database::all(
            "SELECT r.id, r.name, r.phone, r.status,
                    COALESCE(d.total,0)  AS deposits,
                    COALESCE(s.total,0)  AS spent,
                    COALESCE(t.total,0)  AS returned,
                    COALESCE(d.last_date, NULL) AS last_deposit
             FROM reps r
             LEFT JOIN (SELECT rep_id, SUM(amount) total, MAX(dep_date) last_date
                        FROM rep_custody_deposits WHERE 1" . self::epochSql('dep_date') . "
                        GROUP BY rep_id) d ON d.rep_id = r.id
             LEFT JOIN (SELECT paid_by_rep_id rid, SUM(amount) total
                        FROM permit_stages WHERE paid_by_rep_id IS NOT NULL AND " . Permit::cashOutSql('')
                        . self::epochSql('entry_date') . "
                        GROUP BY paid_by_rep_id) s ON s.rid = r.id
             LEFT JOIN (SELECT rep_id, SUM(returned) total
                        FROM rep_custody_settlements WHERE 1" . self::epochSql('settle_date') . "
                        GROUP BY rep_id) t ON t.rep_id = r.id
             $w
             ORDER BY r.status='inactive', r.name", $args);

        // النثريات (v39) وتسليمات السائقين (v45) مصروف من نفس العهدة — بيتضافوا
        // لـspent فيتطابق الرصيد هنا مع self::balance() بالمليم. لو واحد اتنسي،
        // الكارت هيقول رقم والكشف يقول رقم تاني.
        $misc = RepMiscExpense::totalsByRep();
        $hand = RepDriverHandover::totalsByRep();
        // v60: عهدة بوالص دفعها المندوب من محفظته — مصروف من نفس العهدة.
        $bcus = RepBillCustody::totalsByRep();
        // التحويلات بين المناديب (v46): مستلَم يتزاد، محوَّل يتخصم.
        $tin  = RepTransfer::inTotalsByRep();
        $tout = RepTransfer::outTotalsByRep();
        foreach ($rows as &$row) {
            $rid = (int) $row['id'];
            $row['deposits']     = round((float) $row['deposits'], 2);
            $row['misc']         = $misc[$rid] ?? 0.0;
            $row['handover']     = $hand[$rid] ?? 0.0;
            $row['bill_custody'] = $bcus[$rid] ?? 0.0;
            $row['transfer_in']  = $tin[$rid] ?? 0.0;
            $row['transfer_out'] = $tout[$rid] ?? 0.0;
            $row['spent']    = round(
                (float) $row['spent'] + $row['misc'] + $row['handover'] + $row['bill_custody'], 2);
            $row['returned'] = round((float) $row['returned'], 2);
            $row['balance']  = round(
                $row['deposits'] + $row['transfer_in']
                - $row['spent'] - $row['returned'] - $row['transfer_out'], 2);
        }
        unset($row);
        return $rows;
    }

    /** إجمالي الفلوس اللي برّه مع كل المناديب دلوقتي (لبطاقة الملخّص). */
    public static function totalOutstanding(): float
    {
        if (!self::available()) { return 0.0; }
        $dep = (float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM rep_custody_deposits WHERE 1" . self::epochSql('dep_date'));
        $spent = (float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM permit_stages
             WHERE paid_by_rep_id IS NOT NULL AND " . Permit::cashOutSql('') . self::epochSql('entry_date'));
        $ret = (float) Database::scalar(
            "SELECT COALESCE(SUM(returned),0) FROM rep_custody_settlements WHERE 1" . self::epochSql('settle_date'));
        // ⚠️ v46: التحويلات بين المناديب **بتلغي بعضها في الإجمالي** — كل تحويل
        //    بيزوّد محفظة ويخصم من تانية بنفس المبلغ، فالفلوس ما خرجتش من نظام
        //    عهدة المناديب. مضيفينها بطرفيها للتوثيق (net = 0) مش لتغيير الرقم.
        $tin = RepTransfer::available() ? RepTransfer::totalAll() : 0.0;   // = tout بالظبط
        return round(
            $dep + $tin - $spent - RepMiscExpense::totalAll() - RepDriverHandover::totalAll()
            - RepBillCustody::totalAll() - $ret - $tin, 2);
    }

    // ─────────────────────────────────────────────── التنزيلات

    public static function findDeposit(int $id): ?array
    {
        return Database::row("SELECT * FROM rep_custody_deposits WHERE id = ?", [$id]);
    }

    /** مصادر تنزيل العهدة (v61) — قايمة مقفولة، الفورم مش مصدر ثقة. */
    public const SOURCES = [
        'bank'    => 'من البنك للمندوب على طول',
        'office'  => 'من درج المكتب',
        'carry'   => 'كان معاه من قبل (رصيد مرحَّل)',
        // v67 — بيتولّد من `/company-safe` بس (نوع الحركة `to_rep`). حسابيًّا زي
        // `bank` بالحرف، والاسم منفصل عشان المالك يعرف الفلوس جت منين فعلاً.
        'company' => 'من خزنة الشركة',
    ];

    /** المصادر اللي بتتحسب «فلوس جديدة داخلة» في نموذج «الخزنة = كل كاش الشركة». */
    private const TOPUP_SOURCES = ['bank', 'company'];

    /** الافتراضي — الحالة الغالبة، ومالهاش أثر على الدرج (قرار المالك Q2). */
    public const SOURCE_DEFAULT = 'bank';

    /**
     * ⚠️⚠️ **المصادر اللي المستخدم يقدر يختارها بإيده من فورم تنزيل العهدة.**
     *
     * `company` **مش منها عن قصد** — بيتولّد من `/company-safe` بس (نوع الحركة
     * `to_rep`) اللي **بيخصم المبلغ من خزنة الشركة في نفس المعاملة**. لو اتحطّ
     * في الفورم، أي حد يقدر ينزّل عهدة «من خزنة الشركة» **بلا ما تتخصم منها**
     * ⇒ فلوس بتتولّد من العدم في محفظة المندوب. نفس منطق استبعاد `from_client`
     * من `CompanySafe::MANUAL_KINDS`.
     */
    public const MANUAL_SOURCES = ['bank', 'office', 'carry'];

    /** خيارات الفورم (مفتاح ⇒ تسمية) — المصدر الوحيد لأي `<select>` مصدر. */
    public static function manualSources(): array
    {
        return array_intersect_key(self::SOURCES, array_flip(self::MANUAL_SOURCES));
    }

    /**
     * ⚠️ **نسخة `cleanSource` للمدخلات الجاية من فورم** — بترجّع الافتراضي لأي
     *    قيمة برّه `MANUAL_SOURCES`، بما فيها `company` الصالحة تقنيًّا. الفورم
     *    مش مصدر ثقة، والفرق بينها وبين `cleanSource` هو الحاجز الوحيد اللي
     *    بيمنع تنزيل «من خزنة الشركة» بلا خصم منها.
     */
    public static function cleanManualSource(?string $v): string
    {
        $v = trim((string) $v);
        return in_array($v, self::MANUAL_SOURCES, true) ? $v : self::SOURCE_DEFAULT;
    }

    /**
     * ⚠️ **الفورم مش مصدر ثقة**: أي قيمة برّه `SOURCES` بترجع للافتراضي `bank`.
     *    قيمة غلط كانت هتتخزَّن في enum فترفضها MySQL ويقع الحفظ، أو الأسوأ —
     *    لو `office` اتكتبت بالغلط الخزنة تتخصم مبلغًا ما خرجش من الدرج.
     */
    public static function cleanSource(?string $v): string
    {
        $v = trim((string) $v);
        return isset(self::SOURCES[$v]) ? $v : self::SOURCE_DEFAULT;
    }

    /**
     * ⚠️⚠️ **المصدر الوحيد لسؤال «خانة المصدر ليها أثر على الخزنة دلوقتي؟»** —
     *    وهو اللي بيقرّر تظهر في الفورم ولا لأ.
     *
     * ═══ ليه النموذجان مع بعض مش `newSafeRuleActive()` لوحده ═══
     * الخانة كانت مشروطة بقاعدة المرحلة ٢ بس، فلما اتفعّل نموذج **«الخزنة = كل
     * كاش الشركة»** (`rep_bank_topup_from`) لوحده على الإنتاج، الخانة فضلت
     * **مخفية** — يعني كل تنزيل بيتسجّل `bank` غصبًا، وده **بيزوّد الخزنة**
     * (`Closing::sumBankDeposits`). فالمالك اللي بياخد كاش من الدرج ويسلّمه
     * لمندوب كان بيلاقي الخزنة **زادت** بالمبلغ وهو ما دخلش أصلاً — الفلوس
     * تتقري مرتين. الصح إن الخانة تظهر في **أي** نموذج المصدر بيفرق فيه.
     *
     * بترجّع false لو النموذجين مطفيين ⇒ كل المصادر واحدة فعلاً، والخانة ساعتها
     * تشتيت بلا أثر (السلوك الأصلي).
     */
    public static function sourceMatters(): bool
    {
        return self::bankTopupActive() || self::newSafeRuleActive();
    }

    /**
     * ⚠️⚠️ **المصدر الوحيد لسؤال «التنزيل ده بيغيّر رصيد الخزنة؟»** — وهو اللي
     *    بيقرّر نحتاج `Closing::guard` ولا لأ (الإنشاء **والحذف**).
     *
     *   • `office` مع قاعدة المرحلة ٢ → كاش خرج من الدرج ⇒ **مصروف** يوم تنزيله.
     *   • `bank`   مع نموذج «الخزنة = كل كاش الشركة» → فلوس جديدة ⇒ **إضافة**.
     *   • `carry`  → عمره ما يمسّ الخزنة (متحسوب خلاص في مرحّل امبارح).
     *   • `office` مع النموذج المجمّع → **تحويل داخلي**: الدرج بينقص وجيب المندوب
     *     بيزيد والاتنين جوّه الرقم ⇒ الخزنة ما بتتغيّرش، فمفيش حارس.
     *
     * ⚠️ من غير الحارس ده، تنزيل مؤرَّخ على **يوم متقفل** بيغيّر رصيدًا مثبَّتًا
     *    (`refreshLockedChainFrom` بتعيد الحساب من الصفوف الخام) **بلا أي خطأ ظاهر**.
     */
    public static function depositAffectsSafe(string $source): bool
    {
        $src = self::cleanSource($source);
        if ($src === 'office') { return self::newSafeRuleActive(); }
        /* ⚠️ `company` (v67) نفس حساب `bank` بالضبط — الاتنين فلوس بتدخل جيب
           المندوب من برّه الدرج. الاسم بس هو اللي بيفرق (للعرض والتتبّع). */
        if (in_array($src, self::TOPUP_SOURCES, true)) { return self::bankTopupActive(); }
        return false;   // carry
    }

    /**
     * شرط «التنزيل ده مصدره كذا؟» — **للعرض والتصنيف، مش للحساب**.
     *
     * ⚠️⚠️ **ما تخلطهاش بـ`officeDepositSql()`**: دي بتقول «الصف مصدره الدرج»
     *    وبس، وتلك بتقول «الصف ده كاش خرج من الدرج **ويتحسب مصروفًا**» — فبتزوّد
     *    حدّ خط الفصل وبترجّع `''` لو القاعدة مطفية. استخدام الغلط منهم في مكان
     *    الحساب بيكسر مطابقة `Closing` بلا أي خطأ ظاهر.
     *
     * ⚠️ قبل `migrate_v61` مفيش عمود `source` وكل الصفوف ضمنيًّا `bank` — فالسؤال
     *    عن أي مصدر تاني بيرجّع شرطًا **مستحيل** (` AND 0`) مش شرطًا فاضيًا،
     *    وإلا كان هيطابق **كل** الصفوف ويعرضهم على إنهم من الدرج.
     */
    public static function sourceIsSql(string $source, string $col = 'source'): string
    {
        $src = self::cleanSource($source);
        if (self::sourceReady()) { return " AND $col = '$src'"; }
        return $src === self::SOURCE_DEFAULT ? '' : ' AND 0';
    }

    /**
     * تنزيلات يوم مقسومة بالمصدر — **للعرض بس** (سطر «منها كذا من الدرج»).
     *
     * ⚠️ نفس نطاق `dayDeposits`/`deposits` بالحرف (خط الفصل + المندوب لو محدَّد)
     *    فمجموع المفاتيح = الرقم المعروض فوقها بالمليم. أي شرط مختلف هنا بيخلّي
     *    سطر التفكيك يخالف الكارت اللي تحته.
     * ⚠️ بترجّع مصفوفة فاضية قبل `migrate_v61` — مفيش عمود مصدر يتقسّم عليه.
     *
     * @return array<string,float> مفاتيحها من `SOURCES` والقيمة > 0 فقط
     */
    public static function depositSourceTotals(string $day, ?int $repId = null): array
    {
        if (!self::available() || !self::sourceReady() || $day === '') { return []; }
        $args = [$day];
        $repW = '';
        if ($repId !== null) { $repW = ' AND rep_id = ?'; $args[] = $repId; }
        $out = [];
        foreach (Database::all(
            "SELECT source, COALESCE(SUM(amount),0) AS total FROM rep_custody_deposits
             WHERE dep_date = ?$repW" . self::epochSql('dep_date') . ' GROUP BY source', $args
        ) as $r) {
            $v = round((float) $r['total'], 2);
            if (abs($v) > 0.005) { $out[self::cleanSource($r['source'])] = $v; }
        }
        return $out;
    }

    /**
     * ⚠️⚠️ **المصدر الوحيد لعرض «التنزيل ده جاي منين»** — شارة قصيرة + أيقونة +
     *    أثره على الخزنة، لكل شاشة بتعرض صف تنزيل عهدة.
     *
     * ═══ ليه دي موجودة (بلاغ المالك 2026-08-08) ═══
     * `source` بيتسجّل من v61 و**عمره ما اتعرض في أي مكان**: كشف حساب المندوب
     * وكارت اليوم ونوافذ التفاصيل كلها كانت بتعرض «تنزيل عهدة» + التاريخ + المبلغ
     * وبس. فالمالك يبص على ٢٠ ألف نازلة لمندوب ومايعرفش طلعت من الدرج اللي
     * بيعدّه بإيده ولا اتسحبت من البنك — وهو الفرق اللي **بيحدّد رصيد الخزنة**.
     *
     * ⚠️ **الأثر بيتبع النموذج الفعّال** (`depositAffectsSafe` هي الحكم، مش اسم
     *    المصدر): نفس التنزيل `office` بيبقى «خصم من الخزنة» في قاعدة المرحلة ٢
     *    و«تحويل داخلي» في النموذج المجمّع. نص ثابت هنا كان هيكدب في نموذج منهم.
     *
     * ⚠️ بترجّع `null` قبل `migrate_v61` — مفيش عمود `source` أصلاً، وادّعاء
     *    «من البنك» على صف ما اتسألش عنه أسوأ من عدم العرض.
     *
     * @return array{key:string,label:string,short:string,icon:string,effect:string}|null
     */
    public static function sourceBadge(?string $source): ?array
    {
        if (!self::sourceReady()) { return null; }
        $key = self::cleanSource($source);
        $meta = [
            'bank'    => ['short' => 'من البنك',       'icon' => 'building'],
            'office'  => ['short' => 'من درج المكتب',  'icon' => 'landmark'],
            'carry'   => ['short' => 'رصيد مرحَّل',     'icon' => 'undo'],
            'company' => ['short' => 'من خزنة الشركة', 'icon' => 'home'],
        ][$key];

        if ($key === 'carry') {
            $effect = 'كان معاه من قبل — مش إضافة جديدة';
        } elseif ($key === 'company') {
            /* ⚠️ نفس حساب `bank` (شوف `depositAffectsSafe`) بس الجملة بتقول
               الفلوس خرجت منين — ودي كل فايدة إن ليه مصدرًا منفصل. */
            $effect = self::bankTopupActive()
                ? 'خرجت من خزنة الشركة ودخلت الخزنة — الإجمالي زي ما هو'
                : 'خرجت من خزنة الشركة — ما دخلتش درج المكتب';
        } elseif ($key === 'office') {
            $effect = self::newSafeRuleActive()
                ? 'اتخصمت من الخزنة يوم التنزيل'
                : (self::bankTopupActive()
                    ? 'تحويل داخلي — الدرج نقص والخزنة زي ما هي'
                    : 'ما بتمسّش خزنة المكتب');
        } else {
            $effect = self::bankTopupActive()
                ? 'فلوس جديدة داخلة — الخزنة زادت'
                : (self::newSafeRuleActive()
                    ? 'ما دخلتش الدرج — برّه رصيد الخزنة'
                    : 'ما بتمسّش خزنة المكتب');
        }

        return ['key' => $key, 'label' => self::SOURCES[$key], 'short' => $meta['short'],
                'icon' => $meta['icon'], 'effect' => $effect];
    }

    /**
     * شرح «الخانة دي بتعمل إيه» بالعامية — **نص واحد للشاشتين** (كارت المندوب
     * وصفحة الخزنة) عشان ما يتفرّعش نصّان يقولوا كلامًا مختلفًا لنفس الإجراء.
     */
    public static function sourceNote(): string
    {
        if (self::newSafeRuleActive()) {
            return '«من درج المكتب» هي الوحيدة اللي بتخصم من الخزنة يوم التنزيل. '
                 . 'اللي بيتسحب من البنك ويتسلّم للمندوب على طول ما بيدخلش الدرج أصلاً.';
        }
        if (self::bankTopupActive()) {
            return 'الخزنة = كل كاش الشركة (الدرج + اللي مع المناديب). '
                 . '«من البنك» فلوس جديدة داخلة ⇒ الخزنة بتزيد. '
                 . '«من درج المكتب» تحويل داخلي ⇒ الخزنة زي ما هي والدرج بس هو اللي بينقص.';
        }
        return 'ما بيخصمش من خزنة المكتب — عهدة المناديب حساب منفصل.';
    }

    /**
     * تنزيل عهدة جديد.
     *
     * ⚠️ **أثره على الخزنة بيتحدّد من `$source` (v61 — المرحلة ٢):**
     *   • `bank`/`carry` → **مالوش أثر على الدرج** (سلوك v42 القديم بالحرف).
     *   • `office`       → **بيتخصم من الدرج يوم التنزيل** (`Closing::sumOfficeDeposits`).
     *  والقاعدة كلها مطفية لو `newSafeRuleActive()` = false، فالسلوك القديم يفضل.
     */
    public static function createDeposit(int $repId, float $amount, ?string $date, string $note = '',
                                        string $source = self::SOURCE_DEFAULT): int
    {
        $src = self::cleanSource($source);
        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            self::lockRep($repId);
            // ⚠️ العمود بيتخطّى قبل migrate_v61 — إدخاله كان هيكسر كل تنزيل.
            $cols = self::sourceReady() ? ', source' : '';
            $vals = self::sourceReady() ? ',?' : '';
            $args = [$repId, $date ?: null, round(abs($amount), 2),
                     $note !== '' ? $note : null, Auth::user()];
            if (self::sourceReady()) { $args[] = $src; }
            Database::exec(
                "INSERT INTO rep_custody_deposits (rep_id, dep_date, amount, note, created_by$cols)
                 VALUES (?,?,?,?,?$vals)",
                $args
            );
            $id = Database::lastId();
            if ($own) { $pdo->commit(); }
            return $id;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    public static function deleteDeposit(int $id): void
    {
        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            $row = Database::row('SELECT * FROM rep_custody_deposits WHERE id=? FOR UPDATE', [$id]);
            if ($row) {
                self::lockRep((int) $row['rep_id']);
                Database::exec('DELETE FROM rep_custody_deposits WHERE id=?', [$id]);
            }
            if ($own) { $pdo->commit(); }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /** تنزيلات مندوب (الأحدث أولاً). `$day` = تنزيلات يوم واحد بس. */
    public static function deposits(int $repId, ?string $day = null): array
    {
        if (!self::available()) { return []; }
        $args = [$repId];
        $w = self::dayClause('dep_date', $day, $args);
        return Database::all(
            "SELECT * FROM rep_custody_deposits WHERE rep_id = ?$w
             ORDER BY (dep_date IS NULL), dep_date DESC, id DESC", $args);
    }

    // ─────────────────────────────────────────────── المصروف (البنود المعتمدة)

    /**
     * البنود اللي المندوب دفعها من عهدته — لكشف الحساب.
     * ٣ مصادر بيتدمجوا في قائمة واحدة مرتّبة بالتاريخ:
     *   • `src='permit'`   — بنود على أذونات (permit_stages، v35)
     *   • `src='misc'`     — نثريات عامة بلا إذن (rep_misc_expenses، v39)
     *   • `src='handover'` — تسليم عهدة لسائق (rep_driver_handovers، v45)
     *   • `src='bill'`     — عهدة سائق على بوليصة أضافها المندوب (v60)
     * صفوف النثريات والتسليمات `permit_no = null`، فالعرض لازم يفحص `src` قبل
     * ما يبني رابط إذن (وإلا يطلع رابط لإذن #0).
     *
     * ⚠️ v45: البنود المدفوعة بالفيزا بتظهر هنا **موسومة `is_visa`** للعلم، لكن
     *    ما بتتحسبش في الرصيد (spentTotal بيفلترها) — العرض بيوضّح ده بشارة.
     *
     * `$day` (اختياري): يوم واحد بس. الفلترة بتحصل على العمود الموحَّد `d` بعد
     * ما الأربع مصادر بيتدمجوا — **مكان واحد** بدل شرط تاريخ متكرر أربع مرات
     * ممكن يفترقوا (نفس منطق `dayClause` فوق).
     */
    public static function spending(int $repId, ?string $day = null): array
    {
        if (!self::available()) { return []; }
        // v57: البند «غير مدفوع» بيظهر هنا موسومًا للعلم زي بند الفيزا بالظبط،
        // لكن `spentTotal` بتفلتره فما بيتحسبش في الرصيد.
        $unpaidCol = Permit::unpaidReady() ? 's.unpaid' : '0';
        $rows = Database::all(
            "SELECT s.id, 'permit' AS src, s.entry_date AS d, s.note, s.qty, s.amount, s.ref_no,
                    s.stage_key, s.direction, NULL AS category, s.is_visa, s.visa_card_id,
                    $unpaidCol AS unpaid,
                    p.id AS permit_id, p.permit_no, p.permit_type, p.company, p.client
             FROM permit_stages s
             JOIN permits p ON p.id = s.permit_id
             WHERE s.paid_by_rep_id = ?" . self::epochSql('s.entry_date'), [$repId]);

        foreach (RepMiscExpense::forRep($repId) as $m) {
            $rows[] = [
                'id' => (int) $m['id'], 'src' => 'misc', 'd' => $m['exp_date'],
                'note' => $m['note'], 'qty' => (int) $m['qty'], 'amount' => $m['amount'],
                'ref_no' => $m['ref_no'], 'stage_key' => 'custom', 'direction' => 'expense',
                'category' => $m['category'],
                'misc_kind' => $m['misc_kind'] ?? null,   // v71 — وسم النثرية للأيقونة والشارة
                'is_visa' => (int) ($m['is_visa'] ?? 0), 'visa_card_id' => $m['visa_card_id'] ?? null,
                'unpaid' => 0,
                'permit_id' => null, 'permit_no' => null, 'permit_type' => null,
                'company' => null, 'client' => null,
            ];
        }

        foreach (RepDriverHandover::forRep($repId) as $h) {
            $rows[] = [
                'id' => (int) $h['id'], 'src' => 'handover', 'd' => $h['handover_date'],
                'note' => $h['note'], 'qty' => 1, 'amount' => $h['amount'],
                'ref_no' => null, 'stage_key' => 'custom', 'direction' => 'expense',
                'category' => 'تسليم سائق',
                // التسليم كاش دايمًا — مفيش فيزا في تسليم اليد
                'is_visa' => 0, 'visa_card_id' => null, 'unpaid' => 0,
                'driver_name' => $h['driver_name'], 'car_no' => $h['car_no'],
                'bawali_id' => $h['bawali_id'] !== null ? (int) $h['bawali_id'] : null,
                'bill_number' => $h['bill_number'] ?? null,
                'permit_id' => $h['permit_id'] !== null ? (int) $h['permit_id'] : null,
                'permit_no' => $h['permit_no'] ?? null,
                'permit_type' => $h['permit_type'] ?? null,
                'company' => null, 'client' => null,
            ];
        }

        // v60 — عهدة بوليصة دفعها المندوب من محفظته.
        // ⚠️ **المبلغ = `العهدة − 100`** (`Custody::billDeduction`) مش العهدة
        //    كاملة: الـ100 رقم ورقي بيتساب لصاحب البوليصة، والسائق بياخد الباقي
        //    فعلاً (تصحيح 2026-07-30). لازم يطابق `RepBillCustody::totalFor`
        //    بالمليم وإلا الكشف يقول رقم والرصيد يقول رقم تاني.
        foreach (RepBillCustody::forRep($repId) as $b) {
            $rows[] = [
                'id' => (int) $b['id'], 'src' => 'bill', 'd' => $b['bill_date'],
                'note' => $b['bill_number'] ? ('بوليصة ' . $b['bill_number']) : null,
                'qty' => 1, 'amount' => Custody::billDeduction((float) $b['custody'], $b['car_no'] ?? null, $b['bill_date'] ?? null),
                'gross_custody' => round((float) $b['custody'], 2),
                'ref_no' => $b['bill_number'], 'stage_key' => 'custom', 'direction' => 'expense',
                'category' => 'عهدة سائق على بوليصة',
                // العهدة كاش دايمًا — مفيش فيزا في عهدة اليد
                'is_visa' => 0, 'visa_card_id' => null, 'unpaid' => 0,
                'driver_name' => $b['driver'], 'car_no' => $b['car_no'],
                'bawali_id' => (int) $b['id'],
                'bill_number' => $b['bill_number'] ?? null,
                'permit_id' => $b['permit_id'] !== null ? (int) $b['permit_id'] : null,
                'permit_no' => $b['permit_no'] ?? null,
                'permit_type' => $b['permit_type'] ?? null,
                'company' => $b['company'] ?? null, 'client' => $b['client'] ?? null,
            ];
        }

        // التحويلات الصادرة لمناديب تانية (v46) — بتخصم من عهدته زي الصرف.
        foreach (RepTransfer::outgoingFor($repId) as $t) {
            $rows[] = [
                'id' => (int) $t['id'], 'src' => 'transfer', 'd' => $t['wallet_date'] ?? $t['transfer_date'],
                'note' => $t['note'], 'qty' => 1, 'amount' => $t['amount'],
                'ref_no' => null, 'stage_key' => 'custom', 'direction' => 'expense',
                'category' => 'تحويل لمندوب',
                'is_visa' => 0, 'visa_card_id' => null, 'unpaid' => 0,
                'other_name' => $t['other_name'] ?? '',
                'permit_id' => null, 'permit_no' => null, 'permit_type' => null,
                'company' => null, 'client' => null,
            ];
        }

        if ($day !== null && $day !== '') {
            $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['d'] ?? '') === $day));
        }

        usort($rows, function ($a, $b) {
            return [$b['d'] ?? '', (int) $b['id']] <=> [$a['d'] ?? '', (int) $a['id']];
        });
        return $rows;
    }

    /**
     * أداء المندوب في يوم واحد — قراءة فقط، مبنية **كلها** من `spending()`
     * فمستحيل الأعداد تخالف كشف الصرف نفسه.
     *
     * التصنيف: إيصال (`stage_key='receipts'`) · مصروف إذن · نثرية · تسليم سائق ·
     * تحويل لزميل. و`visa`/`unpaid` بندان معلوماتيان — **بيتعرضا للعلم وما
     * بيتخصموش من عهدة المندوب**، عشان كده `cash` منفصل عن `total`.
     *
     * @return array{receipts:array,expenses:array,misc:array,handover:array,
     *               transfer:array,visa:array,unpaid:array,cash:float,total:float,rows:array}
     */
    public static function dayActivity(int $repId, string $day): array
    {
        $bucket = static fn (): array => ['cnt' => 0, 'sum' => 0.0];
        $out = [
            'receipts' => $bucket(), 'expenses' => $bucket(), 'misc' => $bucket(),
            'handover' => $bucket(), 'bill' => $bucket(), 'transfer' => $bucket(),
            'visa' => $bucket(), 'unpaid' => $bucket(),
            'cash' => 0.0, 'total' => 0.0, 'rows' => [],
        ];
        if (!self::available() || $day === '') { return $out; }

        $out['rows'] = self::spending($repId, $day);
        foreach ($out['rows'] as $r) {
            $amt = round((float) $r['amount'], 2);
            $key = match ((string) ($r['src'] ?? 'permit')) {
                'misc'     => 'misc',
                'handover' => 'handover',
                'bill'     => 'bill',
                'transfer' => 'transfer',
                default    => ((string) ($r['stage_key'] ?? '') === 'receipts' ? 'receipts' : 'expenses'),
            };
            $out[$key]['cnt']++;
            $out[$key]['sum'] = round($out[$key]['sum'] + $amt, 2);
            if (!empty($r['unpaid'])) {
                $out['unpaid']['cnt']++;
                $out['unpaid']['sum'] = round($out['unpaid']['sum'] + $amt, 2);
            } elseif (!empty($r['is_visa'])) {
                $out['visa']['cnt']++;
                $out['visa']['sum'] = round($out['visa']['sum'] + $amt, 2);
            } else {
                $out['cash'] = round($out['cash'] + $amt, 2);
            }
            $out['total'] = round($out['total'] + $amt, 2);
        }
        return $out;
    }

    // ─────────────────────────────────────────────── التسويات

    public static function findSettlement(int $id): ?array
    {
        return Database::row("SELECT * FROM rep_custody_settlements WHERE id = ?", [$id]);
    }

    public static function settlements(int $repId): array
    {
        if (!self::available()) { return []; }
        return Database::all(
            "SELECT * FROM rep_custody_settlements WHERE rep_id = ?" . self::epochSql('settle_date') . "
             ORDER BY settle_date DESC, id DESC", [$repId]);
    }

    /**
     * تسويات يوم واحد **عبر كل المناديب** — «استلمت كام من المناديب النهاردة».
     *
     * ⚠️ **التاريخ هو `settle_date` مش وقت التسجيل** — التصفية بتتسجّل بتاريخ
     *    اليوم اللي بتقفله (قاعدة 2026-07-27)، فالمالك اللي واقف على تقفيل يوم
     *    D بيشوف اللي استلمه عن يوم D بالظبط.
     *
     * ⚠️ **المبلغ ممكن يطلع سالب** ومعناه إن المكتب **دفع** للمندوب (كان فارش
     *    من جيبه أكتر من عهدة اليوم). ممنوع يتقصّ على صفر (قاعدة docs/16 §10.5).
     *
     * @return array<int,array> صفوف التسوية + `rep_name`
     */
    public static function settlementsOn(string $day): array
    {
        if (!self::available() || $day === '') { return []; }
        return Database::all(
            "SELECT s.*, r.name AS rep_name
               FROM rep_custody_settlements s
               LEFT JOIN reps r ON r.id = s.rep_id
              WHERE s.settle_date = ?" . self::epochSql('s.settle_date') . "
              ORDER BY s.id", [$day]);
    }

    /** إجمالي اللي اتستلم من المناديب في يوم (السالب بيعدّي زي ما هو). */
    public static function settledTotalOn(string $day): float
    {
        if (!self::available() || $day === '') { return 0.0; }
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(returned),0) FROM rep_custody_settlements
              WHERE settle_date = ?" . self::epochSql('settle_date'), [$day]), 2);
    }

    /**
     * ⚠️⚠️ **الأيام اللي لسه فيها رصيد مفتوح** — أساس التصفية كلها (2026-07-27).
     *
     * **الاكتشاف اللي بنى المنطق ده (متحقَّق على بيانات الإنتاج):**
     *     مجموع أرصدة كل الأيام = الرصيد التراكمي **بالظبط**.
     * وده مش صدفة: كل صف في كل مصدر (تنزيل/صرف/مرتجع/تحويل) له تاريخ، فالتراكمي
     * مش رقم مستقل — هو حاصل جمع الأيام. يعني **لو كل يوم اتقفل على تاريخه،
     * التراكمي بيقع صفر لوحده** ومفيش تناقض بين «عهدة اليوم» و«برّه معاه».
     *
     * ده حلّ التناقض اللي كان بيطلّع أرقامًا غلط: التصفية القديمة كانت بتاخد
     * **مبلغًا تراكميًّا** وتسجّله على **يوم واحد**، فاليوم ده بيتلوّث. (اتصاد
     * فعليًّا: تسوية بـ−4,845 على 2026-07-26 خلّت عهدة اليوم ده تقرا 6,845
     * والمنزَّل فيه 2,000 بس.)
     *
     * @return array<int,array> الأقدم أولاً، كل صف = صف `dayLedger` + `pending_cnt`
     */
    public static function openDays(int $repId, int $limit = 400): array
    {
        $rows = array_values(array_filter(
            self::dayLedger($repId, $limit),
            static fn (array $d): bool => abs((float) $d['balance']) > 0.005
                                       || (float) $d['pending'] > 0.005
        ));
        usort($rows, static fn ($a, $b) => strcmp((string) $a['day'], (string) $b['day']));
        if (!$rows) { return []; }

        // عدد البنود المستنية لكل يوم — استعلام واحد لكل المندوب.
        $cnt = [];
        foreach (Database::all(
            "SELECT entry_date d, COUNT(*) c FROM rep_submissions
             WHERE rep_id = ? AND status = 'pending'" . self::epochSql('entry_date') . "
             GROUP BY entry_date", [$repId]) as $r) {
            $cnt[(string) $r['d']] = (int) $r['c'];
        }
        foreach ($rows as &$row) { $row['pending_cnt'] = $cnt[(string) $row['day']] ?? 0; }
        unset($row);
        return $rows;
    }

    /**
     * لقطة «تصفية العهدة» — الأرقام اللي زرار التصفية بيشتغل عليها.
     *
     * ⚠️ **المصدر الوحيد لمبلغ التصفية** — الكنترولر والشاشتان (المكتب والموبايل)
     *    كلهم بيقروا منها، فمستحيل الشاشة تعرض رقمًا والزرار يقفل رقمًا تاني.
     *
     * `$day = null` → كل الأيام المفتوحة (وده اللي بيصفّر التراكمي كمان).
     * `$day = 'YYYY-MM-DD'` → اليوم ده بس.
     *
     * ⚠️ **قرار المالك (2026-07-27): البنود المستنية بتوقف التصفية.**
     *    قبل كده كان `amount = الرصيد − المستنّي`، فالزرار كان بيسيب وراه رصيدًا
     *    = المستنّي والمالك يقول «مش بيصفّر». وأسوأ: لو المستنّي أكبر من الرصيد
     *    كان بيسجّل تسوية **بالسالب** (حصل فعليًّا مرتين: −2,830 و−4,845).
     *    دلوقتي: `amount` = الرصيد كامل، و`blocked=true` لو فيه بند مستنّي —
     *    الشاشة بتقول «راجع البنود الأول» وبتوديه للصندوق، وبعد الاعتماد
     *    التصفية بتصفّر **بالمليم**.
     */
    public static function clearSnapshot(int $repId, ?string $day = null): array
    {
        $days = self::openDays($repId);
        if ($day !== null && $day !== '') {
            $days = array_values(array_filter(
                $days, static fn (array $d): bool => (string) $d['day'] === $day));
        }
        $amount = $pending = 0.0; $pendingCnt = 0; $locked = [];
        foreach ($days as $d) {
            $amount     += (float) $d['balance'];
            $pending    += (float) $d['pending'];
            $pendingCnt += (int) $d['pending_cnt'];
            if (!empty($d['locked'])) { $locked[] = (string) $d['day']; }
        }
        // تفكيك تراكمي **للعرض بس** (شاشة المندوب بتشرح الرقم جه منين).
        $b = self::breakdown($repId);
        return [
            'day'         => $day,
            'days'        => $days,
            'day_count'   => count($days),
            'deposits'     => $b['deposits'],
            'spent'        => $b['spent'],
            'returned'     => $b['returned'],
            'transfer_in'  => $b['transfer_in'],
            'transfer_out' => $b['transfer_out'],
            'amount'      => round($amount, 2),
            'pending'     => round($pending, 2),
            'pending_cnt' => $pendingCnt,
            'locked_days' => $locked,
            'blocked'     => $pendingCnt > 0,
            // التراكمي — بيساوي مجموع كل الأيام المفتوحة (متحقَّق في الفاحص)
            'balance'     => $b['balance'],
        ];
    }

    /**
     * **تصفية العهدة بضغطة واحدة — كل يوم بيتقفل على تاريخه هو.**
     *
     * ⚠️ **أهم قاعدة هنا:** التسوية بتتسجّل **بتاريخ اليوم اللي بتقفله**، مش
     *    بتاريخ النهاردة. من غير كده الرصيد التراكمي يتصفّر لكن **عهدة اليوم
     *    اللي اتسجّلت عليه تتلوّث** برقم من أيام تانية — وده بالظبط اللي كان
     *    بيحصل (شوف `openDays`).
     *
     * • `$day = null`  → بيقفل **كل** الأيام المفتوحة، تسوية لكل يوم على تاريخه.
     *                     النتيجة: كل يوم = صفر **والتراكمي = صفر** مع بعض.
     * • `$day = 'Y-m-d'` → بيقفل اليوم ده بس.
     *
     * • مبلغ موجب = المندوب سلّم الفاضل معاه للمكتب.
     * • مبلغ سالب = المكتب دفع له (كان صارف من جيبه أكتر من عهدة اليوم ده).
     *
     * ⚠️ **بترفض لو فيه بند مستنّي مراجعة** (قرار المالك) — مفيش مبلغ بيتقفل
     *    والمالك مش شايفه.
     * ⚠️ **مالهاش أي أثر على الخزنة** (قاعدة v42): الفلوس ماخرجتش من الخزنة وقت
     *    التنزيل — الخزنة بتتخصم وقت الصرف. عشان كده **مفيش `Closing::guard`**.
     *
     * @return array{days:int,amount:float,ids:array<int>}
     */
    public static function settleZero(int $repId, ?string $day = null, string $note = '', ?string $by = null): array
    {
        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            self::lockRep($repId);
            // ⚠️ اللقطة بتتقرا **بعد** القفل: تصفيتان متزامنتان مايقروش نفس الرصيد.
            $snap = self::clearSnapshot($repId, $day);

            if ($snap['blocked']) {
                throw new \RuntimeException(
                    'فيه ' . $snap['pending_cnt'] . ' بند مستني مراجعتك بـ'
                    . number_format($snap['pending'], 2, '.', ',')
                    . ' — راجعهم الأول من «مراجعة بنود المناديب»، وبعدين التصفية هتصفّر الحساب بالظبط.');
            }

            $ids = []; $total = 0.0;
            foreach ($snap['days'] as $d) {
                $amount = round((float) $d['balance'], 2);
                if (abs($amount) < 0.005) { continue; }
                Database::exec(
                    "INSERT INTO rep_custody_settlements
                        (rep_id, settle_date, deposits_total, spent_total, returned,
                         to_custody, custody_id, note, settled_by)
                     VALUES (?,?,?,?,?,?,?,?,?)",
                    [$repId, $d['day'], $d['deposits'], $d['spent'], $amount, 0, null,
                     $note !== '' ? $note : 'تصفية عهدة يوم ' . $d['day'], $by ?? Auth::user()]
                );
                $ids[] = Database::lastId();
                $total = round($total + $amount, 2);
            }
            if (!$ids) {
                throw new \RuntimeException('العهدة مصفّرة أصلاً — مفيش يوم مفتوح يتقفل.');
            }
            if ($own) { $pdo->commit(); }
            return ['days' => count($ids), 'amount' => $total, 'ids' => $ids];
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /**
     * تسوية: المندوب رجّع $returned من الباقي معاه.
     * بتخزّن لقطة بالأرقام وقتها (للطباعة) وبتخصم المرتجع من الرصيد.
     *
     * ⚠️ **v42 — ترحيل المرتجع للخزنة اتلغى تمامًا (كان $toCustody).**
     * بقى تحسيبًا مزدوجًا: من v42 تنزيل العهدة للمندوب **مالوش أثر على الخزنة**،
     * والصرف هو اللي بيتخصم يوم صرفه. فالفلوس اللي رجعت **ماخرجتش من الخزنة أصلاً**،
     * وإضافتها كانت هتزوّد الرصيد بفلوس وهمية. التسوية بقت تسجيل في محفظة المندوب بس.
     * البارامتر فضل في التوقيع للتوافق الرجعي وبيتتجاهل؛ العمود to_custody فضل
     * في القاعدة عشان التسويات القديمة (لو وُجدت) تفضل مقروءة زي ما هي.
     */
    public static function settle(int $repId, float $returned, string $date, bool $toCustody = false, string $note = ''): int
    {
        $returned = round(abs($returned), 2);
        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            self::lockRep($repId);
            // الحساب يتم بعد القفل؛ تسويتان متزامنتان لا تقرآن الرصيد القديم نفسه.
            $b = self::breakdown($repId);
            if ($returned > $b['balance'] + 0.005) {
                $label = $b['balance'] < 0 ? 'المستحق للمندوب' : 'الباقي مع المندوب';
                throw new \RuntimeException(
                    'المرتجع (' . number_format($returned, 2) . ") أكبر من $label ("
                    . number_format(abs($b['balance']), 2) . ').');
            }
            // v42: مفيش ترحيل للخزنة خالص — $toCustody متجاهَل و$custodyId بيفضل null.
            Database::exec(
                "INSERT INTO rep_custody_settlements
                    (rep_id, settle_date, deposits_total, spent_total, returned, to_custody, custody_id, note, settled_by)
                 VALUES (?,?,?,?,?,?,?,?,?)",
                [$repId, $date, $b['deposits'], $b['spent'], $returned,
                 0, null, $note !== '' ? $note : null, Auth::user()]
            );
            $id = Database::lastId();
            if ($own) { $pdo->commit(); }
            return $id;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /** حذف تسوية — بيشيل معاها صف الخزنة المولَّد لو كان اترحّل. */
    public static function deleteSettlement(int $id): void
    {
        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            $row = Database::row('SELECT * FROM rep_custody_settlements WHERE id=? FOR UPDATE', [$id]);
            if (!$row) { if ($own) { $pdo->commit(); } return; }
            self::lockRep((int) $row['rep_id']);
            // فك رابط FK أولًا، ثم احذف أثر الخزنة المرتبط داخل المعاملة نفسها.
            Database::exec("DELETE FROM rep_custody_settlements WHERE id = ?", [$id]);
            if (!empty($row['custody_id'])) {
                Database::exec("DELETE FROM manual_custody WHERE id = ?", [(int) $row['custody_id']]);
            }
            if ($own) { $pdo->commit(); }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /** mutex مالي موحّد لكل عمليات عهدة مندوب واحد. */
    private static function lockRep(int $repId): void
    {
        if (!Database::row('SELECT id FROM reps WHERE id=? FOR UPDATE', [$repId])) {
            throw new \RuntimeException('المندوب غير موجود.');
        }
    }

    // ─────────────────────────────────────────────── لوحة المتابعة (الداشبورد)

    /**
     * ملخّص عهدة المناديب ومدخلاتهم للوحة التحكم — **قراءة فقط**.
     *
     * ⚠️ ما بيكتبش ولا بيحسب أي حاجة جديدة: بيقرا نفس مصادر الحقيقة اللي بتقراها
     * صفحة /rep-custody بالظبط (deposits − spent − returned)، فمفيش تعريف تاني
     * للرصيد ينفع يختلف عنها. **لا يمسّ الخزنة ولا التقفيل ولا العهدة العامة.**
     *
     * $day = تاريخ العمل (Auth::workDate) — بيتحسب عليه نشاط اليوم بس.
     *
     * ⚠️⚠️ **الداشبورد بقى يوم واحد بس (قرار المالك 2026-07-27).** عهدة المناديب
     *    يومية بلا ترحيل (v49)، فعرض أرقام تراكمية في لوحة التحكم كان بيدّي صورة
     *    غلط: المالك بيفتح عشان يعرف «أخدوا كام النهاردة وصرفوا كام النهاردة».
     *    عشان كده:
     *      • قايمة `reps` فيها **المناديب اللي عليهم حركة في اليوم ده بس**،
     *        مرتّبة بفاضل اليوم.
     *      • `feed` بيتفلتر على `entry_date = $day`.
     *      • `low` («محتاج تنزيل») بقت على **عهدة اليوم** مش على التراكمي.
     *      • `totals.day_*` هي أرقام العرض؛ والمفاتيح التراكمية القديمة
     *        (`outstanding`/`balance`/`spent`…) **باقية في الحزمة** لأنها الحقيقة
     *        المالية وأساس التسوية وبيقراها `verify_rep_dashboard` — بس
     *        **الشاشة ما بتعرضش منها حاجة**. مكان التراكمي `/rep-custody`.
     */
    public static function dashboard(string $day, int $feedLimit = 10, int $cardLimit = 12): array
    {
        $empty = [
            'available' => false, 'reps' => [], 'feed' => [],
            'totals' => ['deposits'=>0.0,'spent'=>0.0,'returned'=>0.0,'outstanding'=>0.0,
                         'pending_cnt'=>0,'pending_sum'=>0.0,'reps'=>0,'low'=>0,
                         'day_deposits'=>0.0,'day_spent'=>0.0,'day_balance'=>0.0,'day_pocket'=>0.0,
                         'day_returned'=>0.0,'day_pending_cnt'=>0,'day_pending_sum'=>0.0,'day_reps'=>0],
            'day' => ['sent_cnt'=>0,'sent_sum'=>0.0,'paid_cnt'=>0,'paid_sum'=>0.0],
        ];
        $miscReady = RepMiscExpense::available();
        $miscVisaReady = $miscReady && RepMiscExpense::visaReady();
        // ⚠️ العدّ يشمل **كل** المستنّي (زي RepInbox::pendingCount وشارة التبويب)،
        //    لكن المبلغ **كاش بس** (الفيزا مش بتتخصم من العهدة — نفس منطق
        //    RepPortal::pendingAmount). فصلناهم عشان ما يبقاش عدد ومبلغ متعارضين.
        $pendingSum = $miscVisaReady
            ? 'SUM(CASE WHEN is_visa=0 THEN amount ELSE 0 END)'
            : 'SUM(amount)';
        // ⚠️ خط الفصل هنا كمان — الجوين ده كان **آخر نقطة مزامنة اتنست**، فكان
        //    بيطرح نثريات **كل الأيام** من رصيد مفلتر ⇒ «برّه مع المناديب» في
        //    الداشبورد بيقلّ عن `totalOutstanding()` بمقدار النثريات القديمة
        //    (مقيس على الإنتاج: 81,000 مقابل 54,805 = فرق 26,195 بالظبط).
        //    `bin/verify_rep_dashboard.php` هو اللي مسكها.
        $miscJoin = $miscReady
            ? "LEFT JOIN (SELECT rep_id, SUM(amount) total FROM rep_misc_expenses WHERE "
                . RepMiscExpense::cashOnlySql() . self::epochSql('exp_date')
                . " GROUP BY rep_id) x ON x.rep_id=r.id"
            : '';
        $miscSelect = $miscReady ? 'COALESCE(x.total,0)' : '0';
        $loadCards = static function (string $handoverSelect, string $handoverJoin) use (
            $miscSelect, $miscJoin, $pendingSum, $day
        ): array {
            return Database::all(
                "SELECT r.id, r.name, r.phone, r.status,
                    COALESCE(d.total,0) deposits,
                    COALESCE(s.total,0) spent,
                    COALESCE(t.total,0) returned,
                    d.last_date last_deposit,
                    $miscSelect misc,
                    $handoverSelect handover,
                    COALESCE(p.cnt,0) _pending_cnt,
                    COALESCE(p.total,0) _pending_sum,
                    s.last_spend _last_spend,
                    (SELECT COUNT(*) FROM rep_submissions WHERE entry_date=?" . self::epochSql('entry_date') . ") _sent_cnt,
                    (SELECT COALESCE(SUM(amount),0) FROM rep_submissions WHERE entry_date=?" . self::epochSql('entry_date') . ") _sent_sum,
                    (SELECT COUNT(*) FROM permit_stages WHERE paid_by_rep_id IS NOT NULL AND " . Permit::cashOutSql('') . " AND entry_date=?" . self::epochSql('entry_date') . ") _paid_cnt,
                    (SELECT COALESCE(SUM(amount),0) FROM permit_stages WHERE paid_by_rep_id IS NOT NULL AND " . Permit::cashOutSql('') . " AND entry_date=?" . self::epochSql('entry_date') . ") _paid_sum
                 FROM reps r
                 LEFT JOIN (SELECT rep_id, SUM(amount) total, MAX(dep_date) last_date
                            FROM rep_custody_deposits WHERE 1" . self::epochSql('dep_date') . " GROUP BY rep_id) d ON d.rep_id=r.id
                 LEFT JOIN (SELECT paid_by_rep_id rep_id, SUM(amount) total, MAX(entry_date) last_spend
                            FROM permit_stages WHERE paid_by_rep_id IS NOT NULL AND " . Permit::cashOutSql('') . self::epochSql('entry_date') . " GROUP BY paid_by_rep_id) s ON s.rep_id=r.id
                 LEFT JOIN (SELECT rep_id, SUM(returned) total
                            FROM rep_custody_settlements WHERE 1" . self::epochSql('settle_date') . " GROUP BY rep_id) t ON t.rep_id=r.id
                 LEFT JOIN (SELECT rep_id, COUNT(*) cnt, $pendingSum total
                            FROM rep_submissions WHERE status='pending'" . self::epochSql('entry_date') . " GROUP BY rep_id) p ON p.rep_id=r.id
                 $miscJoin
                 $handoverJoin
                 ORDER BY r.status='inactive', r.name",
                [$day, $day, $day, $day]
            );
        };
        // الرصيد + المنتظر + آخر صرف لكل المناديب في تجميعة واحدة.
        try {
            $allCards = $loadCards(
                'COALESCE(h.total,0)',
                'LEFT JOIN (SELECT rep_id, SUM(amount) total FROM rep_driver_handovers WHERE 1'
                . self::epochSql('handover_date') . ' GROUP BY rep_id) h ON h.rep_id=r.id'
            );
        } catch (\PDOException $error) {
            if ((string) $error->getCode() === '42S02') {
                // قبل v45 لا يوجد جدول التسليم؛ أعد نفس التجميعة بصفر ثابت.
                try { $allCards = $loadCards('0', ''); }
                catch (\PDOException $fallbackError) {
                    if (!in_array((string) $fallbackError->getCode(), ['42S02', '42S22'], true)) { throw $fallbackError; }
                    return $empty;
                }
            } elseif ((string) $error->getCode() === '42S22') {
                // قبل migrate_v35 تختفي اللوحة بأمان بدل تعطيل الداشبورد كله.
                return $empty;
            } else { throw $error; }
        }
        // التحويلات بين المناديب (v46) — نضيفها في PHP (زي النثريات في cards) بدل
        // ما نعقّد استعلام الكروت وحيلة الـfallback بتاعته.
        $tin  = RepTransfer::inTotalsByRep();
        $tout = RepTransfer::outTotalsByRep();
        // v60 — عهد بوالص دفعها المندوب من محفظته. بتتضاف في PHP (زي التحويلات
        // فوق) بدل ما تعقّد استعلام الكروت وحيلة الـfallback بتاعته.
        $bcus = RepBillCustody::totalsByRep();
        // v49 — عهدة اليوم لكل مندوب (بلا ترحيل). بتتعرض جنب التراكمي مش بدله:
        // التراكمي هو الفلوس اللي برّه معاه فعلاً، واليوم هو اللي بيسحب منه دلوقتي.
        $dayMap = self::dayTotalsByRep($day);
        // عدد البنود المستنية **في اليوم ده** لكل مندوب (dayTotalsByRep بترجّع
        // المبلغ بس). العدّ يشمل الفيزا والمبلغ كاش بس — نفس تعريف التراكمي فوق.
        $dayPendCnt = [];
        foreach (Database::all(
            "SELECT rep_id rid, COUNT(*) cnt FROM rep_submissions
             WHERE status = 'pending' AND entry_date = ?" . self::epochSql('entry_date') . "
             GROUP BY rep_id", [$day]) as $r) {
            $dayPendCnt[(int) $r['rid']] = (int) $r['cnt'];
        }
        $daySummary = $allCards[0] ?? [];
        foreach ($allCards as &$card) {
            $rid = (int) $card['id'];
            $dayRow = $dayMap[$rid] ?? null;
            $card['day_deposits'] = $dayRow['deposits'] ?? 0.0;
            $card['day_spent']    = $dayRow['spent'] ?? 0.0;
            $card['day_pending']  = $dayRow['pending'] ?? 0.0;
            $card['day_balance']  = $dayRow['balance'] ?? 0.0;
            $card['day_pocket']   = $dayRow['pocket'] ?? 0.0;
            $card['day_returned']     = $dayRow['returned'] ?? 0.0;
            $card['day_transfer_in']  = $dayRow['transfer_in'] ?? 0.0;
            $card['day_transfer_out'] = $dayRow['transfer_out'] ?? 0.0;
            $card['day_pending_cnt']  = $dayPendCnt[$rid] ?? 0;
            $card['deposits'] = round((float) $card['deposits'], 2);
            $card['handover'] = round((float) $card['handover'], 2);
            $card['bill_custody'] = $bcus[$rid] ?? 0.0;
            $card['spent'] = round((float) $card['spent'] + (float) $card['misc']
                                   + $card['handover'] + $card['bill_custody'], 2);
            $card['returned'] = round((float) $card['returned'], 2);
            $card['misc'] = round((float) $card['misc'], 2);
            $card['transfer_in']  = $tin[$rid] ?? 0.0;
            $card['transfer_out'] = $tout[$rid] ?? 0.0;
            $card['balance'] = round(
                $card['deposits'] + $card['transfer_in']
                - $card['spent'] - $card['returned'] - $card['transfer_out'], 2);
            unset($card['_sent_cnt'], $card['_sent_sum'], $card['_paid_cnt'], $card['_paid_sum']);
        }
        unset($card);
        $outstanding = round(array_sum(array_column($allCards, 'balance')), 2);
        $reps = []; $low = 0;
        $pendCnt = 0; $pendSum = 0.0;
        foreach ($allCards as $c) {
            $p = ['cnt' => (int) $c['_pending_cnt'], 'sum' => round((float) $c['_pending_sum'], 2)];
            $lastSpend = $c['_last_spend'];
            $pendCnt += $p['cnt']; $pendSum += $p['sum'];
            unset($c['_pending_cnt'], $c['_pending_sum'], $c['_last_spend']);
            if (($c['status'] ?? 'active') !== 'active') { continue; }
            $c['pending_cnt'] = $p['cnt'];
            $c['pending_sum'] = $p['sum'];
            $c['pocket']      = round($c['balance'] - $p['sum'], 2);   // الفاضل فعليًا بعد المستنّي
            $c['last_spend']  = $lastSpend;
            // نسب شريط الاستهلاك (للعرض فقط)
            $d = (float) $c['deposits'];
            $c['pc_spent']   = $d > 0 ? min(100, round($c['spent']    / $d * 100, 1)) : 0.0;
            $c['pc_pending'] = $d > 0 ? min(100, round($p['sum']      / $d * 100, 1)) : 0.0;
            $c['pc_return']  = $d > 0 ? min(100, round($c['returned'] / $d * 100, 1)) : 0.0;
            // ⚠️ «محتاج تنزيل» بقت على **عهدة اليوم**: فاضل اليوم أقل من 15% من
            //    اللي نزل له فيه (أو صرف من غير ما ينزل له حاجة النهاردة).
            $dd = (float) $c['day_deposits'] + (float) $c['day_transfer_in'];
            $c['low'] = $dd > 0
                ? ($c['day_pocket'] < $dd * 0.15)
                : ($c['day_spent'] > 0 || $c['day_pending'] > 0);
            if ($c['low']) { $low++; }
            // ⚠️ اللي عليه حركة **في اليوم ده** بس — المندوب اللي مالوش حركة
            //    النهاردة مالوش عهدة يوم أصلاً (بلا ترحيل، v49) فوجوده بصفر تشتيت.
            if ($dd > 0 || $c['day_spent'] > 0 || $c['day_pending'] > 0
                || abs((float) $c['day_returned']) > 0.005 || $c['day_transfer_out'] > 0) {
                $reps[] = $c;
            }
        }
        // الأهم فوق: المحتاج تنزيل، ثم الأكبر فاضلًا من عهدة اليوم
        usort($reps, function ($a, $b) {
            if ($a['low'] !== $b['low']) { return $a['low'] ? -1 : 1; }
            return $b['day_pocket'] <=> $a['day_pocket'];
        });
        // الإجماليات تظل على كل المناديب، بينما العرض يقتصر على الأكثر أولوية.
        $summaryReps = $reps;
        $reps = array_slice($reps, 0, max(1, $cardLimit));

        // نشاط اليوم: اللي المناديب بعتوه + اللي اتعتمد فعلاً (بتاريخ الدفع، قاعدة v29)
        // آخر المدخلات (كل الحالات) — بيانات وصفية للمتابعة
        // ⚠️ بنود **يوم العمل** بس — بند من يوم فات مالوش علاقة بعهدة النهاردة.
        $feed = Database::all(
            "SELECT s.id, s.rep_id, s.kind, s.note, s.qty, s.amount, s.entry_date, s.status,
                    s.photo, s.review_note, r.name AS rep_name,
                    p.id AS permit_id, p.permit_no, p.permit_type
             FROM rep_submissions s
             JOIN reps r ON r.id = s.rep_id
             LEFT JOIN permits p ON p.id = s.permit_id
             WHERE s.entry_date = ?" . self::epochSql('s.entry_date') . "
             ORDER BY s.id DESC LIMIT " . max(1, $feedLimit), [$day]);

        return [
            'available' => true,
            'reps' => $reps,
            'feed' => $feed,
            'totals' => [
                'deposits'    => round(array_sum(array_column($summaryReps, 'deposits')), 2),
                'spent'       => round(array_sum(array_column($summaryReps, 'spent')), 2),
                'returned'    => round(array_sum(array_column($summaryReps, 'returned')), 2),
                'outstanding' => $outstanding,
                'pending_cnt' => (int) $pendCnt,
                'pending_sum' => round((float) $pendSum, 2),
                'reps'        => count($summaryReps),
                'low'         => $low,
                // v49 — إجماليات عهدة يوم العمل نفسه (بلا ترحيل من قبله).
                // ⚠️ **دي أرقام العرض في الداشبورد**؛ اللي فوقها تراكمي للتوافق بس.
                'day_deposits'    => round(array_sum(array_column($summaryReps, 'day_deposits')), 2),
                'day_spent'       => round(array_sum(array_column($summaryReps, 'day_spent')), 2),
                'day_balance'     => round(array_sum(array_column($summaryReps, 'day_balance')), 2),
                'day_returned'    => round(array_sum(array_column($summaryReps, 'day_returned')), 2),
                // «فاضل معاهم» = عهدة اليوم − المعتمد − اللي دفعوه ومستني اعتماد.
                // نفس تعريف `pocket` في كل شاشات العهدة — مفيش تعريف تاني.
                'day_pocket'      => round(array_sum(array_column($summaryReps, 'day_pocket')), 2),
                'day_pending_sum' => round(array_sum(array_column($summaryReps, 'day_pending')), 2),
                'day_pending_cnt' => (int) array_sum(array_column($summaryReps, 'day_pending_cnt')),
                'day_reps'        => count($summaryReps),
            ],
            'day' => [
                'sent_cnt' => (int) ($daySummary['_sent_cnt'] ?? 0),
                'sent_sum' => round((float) ($daySummary['_sent_sum'] ?? 0), 2),
                'paid_cnt' => (int) ($daySummary['_paid_cnt'] ?? 0),
                'paid_sum' => round((float) ($daySummary['_paid_sum'] ?? 0), 2),
            ],
        ];
    }

    // ─────────────────────────────────────────────── مساعدات

    /** أسماء المناديب النشطين (للقوائم المنسدلة) — [id => name]. */
    public static function activeReps(): array
    {
        $out = [];
        foreach (Database::all("SELECT id, name FROM reps WHERE status='active' ORDER BY name") as $r) {
            $out[(int) $r['id']] = $r['name'];
        }
        return $out;
    }

    /**
     * قايمة المناديب اللي ينفعوا يتحطّوا في خانتَي «حوّل من / وصلت لـ» في درج
     * تعديل التحويل — **النشطون + أي مندوب موقوف مسجَّل عليه تحويل فعلاً**.
     *
     * ⚠️ **الموقوف لازم يفضل في القايمة.** لو التحويل مسجَّل على مندوب اتوقف
     *    وغاب من `<select>`، المتصفح بيختار **أول اسم في القايمة** بالسكوت —
     *    فالمالك يفتح الدرج يصلّح ملاحظة، ويحفظ، فينتقل المبلغ لمندوب مالوش
     *    دعوة بلا أي رسالة. الموقوف بيتعلّم `(متوقف)` عشان الاختيار يبقى واعيًا،
     *    و`RepTransfer::update()` بيرفض **نقل** تحويل لمندوب موقوف برضه.
     *
     * @param int[] $must مناديب لازم يظهروا مهما كانت حالتهم (أطراف الصفوف المعروضة)
     * @return array<int,string> [id => اسم]
     */
    public static function transferParties(array $must = []): array
    {
        $out = self::activeReps();
        $extra = [];
        foreach ($must as $rid) {
            $rid = (int) $rid;
            if ($rid > 0 && !isset($out[$rid])) { $extra[$rid] = true; }
        }
        if ($extra) {
            $ids = array_keys($extra);
            $in  = implode(',', array_fill(0, count($ids), '?'));
            foreach (Database::all("SELECT id, name FROM reps WHERE id IN ($in)", $ids) as $r) {
                $out[(int) $r['id']] = $r['name'] . ' (متوقف)';
            }
        }
        return $out;
    }

    /** اسم المندوب (للعرض) أو '' لو مش موجود. */
    public static function repName(?int $repId): string
    {
        if (!$repId) { return ''; }
        return (string) (Database::scalar("SELECT name FROM reps WHERE id = ?", [$repId]) ?: '');
    }
}
