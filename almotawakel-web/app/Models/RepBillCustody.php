<?php
namespace App\Models;

use App\Core\Database;

/**
 * عهدة بوليصة مدفوعة من محفظة مندوب (v60).
 *
 * لما المندوب يضيف بوليصة من موبايله ويكتب عهدة السائق، الفلوس دي **طلعت من
 * الفلوس النازلة له** مش من درج المكتب. فالبوليصة بتتوسم بـ`custody_rep_id`،
 * والوسم ده بيعمل حاجتين **لازم يمشوا مع بعض**:
 *
 *   ① بيستثني البوليصة من **خزنة المكتب** (الرصيد · التقفيل · شاشة المراجعة).
 *   ② بيضيف مبلغها لـ**صرف المندوب** في وحدة عهدة المناديب.
 *
 * ⚠️⚠️ **أخطر نقطة في الوحدة — الاتجاهان لازم يفضلوا مع بعض.** استثناء من
 *    الخزنة بلا إضافة لعهدة المندوب = **فلوس بتتبخّر من النظام** (نفس تحذير
 *    v45 عن بنود الفيزا بالحرف). عشان كده الاتنين في الملف ده، والشرط بيتكتب
 *    مرة واحدة في `officeExcludeSql()` — **ممنوع تكتب `custody_rep_id IS NULL`
 *    بإيدك في أي كود خزنة**، وإلا نسيان موضع واحد يبوّظ الرصيد بلا أي خطأ ظاهر.
 *
 * ⚠️⚠️ **المخصوم من المندوب = `العهدة − 100`، نفس قاعدة الخزنة بالظبط.**
 *
 *    v60 كان بيخصم **العهدة كاملة** بناءً على فهم إن الـ100 قاعدة خزنة. وده
 *    **غلط اتصحّح بقرار المالك (2026-07-30)**: الـ100 **رقم ورقي بحت** —
 *    «بتتساب لصاحب البوليصة يسترزق بيها لو باعها». السائق بياخد `custody − 100`
 *    فعلاً، فاللي طلع من إيد المندوب هو `custody − 100` مش `custody`.
 *    خصم العهدة كاملة كان بيضخّم صرف المندوب بـ100 عن كل بوليصة.
 *
 *    المصدر الوحيد للحساب `Custody::deductionSql()` / `Custody::billDeduction()`
 *    — **نفس الدالة اللي بتحسب خصم الخزنة**، بما فيها استثناء السيارات
 *    (`custody_deduction_exempt_cars`: النقلات الخاصة وعربية المكتب بتتخصم
 *    كاملة لأن مفيش صاحب بوليصة يسترزق). ممنوع تكتب `SUM(custody)` هنا.
 *
 * ⚠️ `ready()` بترجّع false قبل `migrate_v60.php` — فالنظام كله يفضل بسلوكه
 *    القديم بالحرف بدل ما يكسر على عمود مش موجود.
 *
 * ⚠️ العزل بالوسم **مش بجدول خاص** (بعكس `rep_misc_expenses` v39 و
 *    `rep_driver_handovers` v45): العهدة هنا **بيان جوهري في البوليصة نفسها**
 *    وبتظهر في الفواتير والكشوف والتقارير زي أي بوليصة تانية — الفرق الوحيد
 *    «مين دفعها». جدول موازٍ كان هيدّي رقمين للعهدة يفترقوا مع أول تعديل.
 */
class RepBillCustody
{
    /**
     * كاش جاهزية العمود.
     * ⚠️ **خاصية ساكنة مش `static $x` جوّه الدالة** عن قصد — دي الطريقة الوحيدة
     *    اللي تخلّي `refreshReady()` تقدر تصفّرها. لو رجعت للـstatic المحلي،
     *    الترحيل اللي بيضيف العمود هيفشل تحقّقه (مصيدة v57 الموثَّقة).
     */
    private static ?bool $ready = null;

    /** هل عمود الوسم موجود؟ (المصدر الوحيد لفحص جاهزية v60) */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            self::$ready = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'bawalis'
                   AND column_name = 'custody_rep_id'"
            );
        }
        return self::$ready;
    }

    /**
     * ⚠️ بيصفّر كاش `ready()`. **إلزامية في أي ترحيل بيضيف العمود ده** — الكاش
     *    بيتقرا قبل الـALTER (وقت قياس الرصيد) فبيفضل false بعده، فتحقّق ما بعد
     *    الترحيل يفشل و**الإصدار ما يتسجّلش في الـledger رغم إن العمود اتضاف
     *    فعلاً**. ده بالظبط اللي حصل في v57 على الإنتاج.
     */
    public static function refreshReady(): void
    {
        self::$ready = null;
        self::ready();
    }

    /** الشرط الفعلي المستخدَم في كل الاستعلامات. */
    private static function on(): bool
    {
        return self::ready();
    }

    // ───────────────────────────────────── ① جانب خزنة المكتب (الاستثناء)

    /**
     * ⚠️ **المصدر الوحيد** لشرط «عهدة البوليصة دي طلعت من خزنة المكتب».
     *
     * بيترجع جزء SQL جاهز للّزق بعد شرط قائم، مثال:
     *     "... WHERE is_legacy = 0 AND custody > 0" . RepBillCustody::officeExcludeSql()
     *     "... WHERE b.is_legacy = 0 AND b.custody > 0" . RepBillCustody::officeExcludeSql('b')
     *
     * بيرجع `''` قبل الترحيل = السلوك القديم بالحرف.
     *
     * ⚠️ ممنوع تكتب الشرط بإيدك في أي مكان تاني — `bin/verify_rep_bill.php`
     *    بيفشل لو أي ملف خزنة كتب `custody_rep_id` بنفسه.
     *
     * ═══ ⚠️⚠️ الاستثناء بيتوقف في النموذج المجمّع (قرار المالك 2026-07-31) ═══
     *
     * النظام له نموذجان لمعنى «رصيد الخزنة» (`RepCustody::bankTopupFrom`):
     *
     *   • **الخزنة = الدرج بس** — محفظة المندوب **برّه** الرقم. فعهدة السائق
     *     اللي دفعها المندوب لازم **تتستثنى**، وإلا بتتخصم من الدرج وهو ما دفعش.
     *   • **الخزنة = كل كاش الشركة** (الشغّال دلوقتي) — محفظة المندوب **جوّه**
     *     الرقم. فعهدة السائق اللي دفعها المندوب لازم **تتخصم زي عهدة الدرج
     *     بالظبط**، لأن الكاش خرج من الشركة فعلاً وراح للسائق.
     *
     * ⚠️⚠️ **الاستثناء في النموذج المجمّع = فلوس بتتبخّر — مقيس.** محفظة المندوب
     *    بتتخصم (`totalFor`) والخزنة لأ، والمحفظة جوّه الخزنة ⇒ الرقم بيقول إن
     *    الفلوس لسه موجودة وهي راحت. اتصاد على الإنتاج: ٢١,٠٠٠ في ٣ بوالص يوم
     *    2026-07-29 كانوا مخفيين تمامًا من التقفيل. (نفس تحذير v45 عن الفيزا
     *    بالحرف، بس في الاتجاه المعكوس.)
     *
     * ⚠️ **الدرج ما بيتأثرش بالتصحيح ده** وده اللي بيثبت إنه صح: الدرج =
     *    الخزنة − Σ المحافظ، والاتنين بينقصوا نفس المبلغ فالفرق ثابت.
     *
     * ⚠️ **الحدّ على `bill_date` بتاع الصف نفسه — وده اللي بيحمي الأيام
     *    المتقفلة.** `Closing::refreshLockedChainFrom()` بتعيد حساب أي يوم متقفل
     *    من الصفوف الخام وبتتنفّذ لوحدها مع «الدخول الحر»؛ لو الحدّ كان على علم
     *    عام كانت إعادة الحساب **هتعيد كتابة تاريخ مقفول بالسكوت** (نفس قاعدة
     *    `RepCustody::safeChargeSql`).
     *
     * ⚠️ **شرط السلامة: `rep_bank_topup_from <= rep_custody_epoch`.** جانب
     *    المحفظة محدود بخط الفصل (`epochSql('bill_date')`)، فلو تاريخ النموذج
     *    المجمّع اتأخّر عنه بتفضل فيه بوالص متخصومة من المحفظة وبرّه الخزنة =
     *    نفس التبخّر. `payerBlock()` بيمنع وسم أي بوليصة قبل خط الفصل أصلاً،
     *    و`bin/verify_rep_bill.php` بيفحص العلاقة دي صراحةً.
     */
    public static function officeExcludeSql(string $alias = ''): string
    {
        if (!self::on()) { return ''; }
        $a = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        if (RepCustody::bankTopupActive()) {
            $f = RepCustody::bankTopupFrom();
            return " AND ({$a}custody_rep_id IS NULL OR {$a}bill_date >= '$f')";
        }
        return " AND {$a}custody_rep_id IS NULL";
    }

    /**
     * ⚠️ **المصدر الوحيد لعرض «مين سلّم السائق»** في استعلامات الخزنة.
     *
     * بعد ما بقت عهدة المندوب تدخل الحساب في النموذج المجمّع، شاشة التقفيل
     * والطباعة لازم يقولوا الكاش خرج من الدرج ولا من جيب المندوب — وإلا الرقم
     * بيزيد والمالك مش عارف ليه.
     *
     * ⚠️ الجوين هنا **مش في ملفات الخزنة** عن قصد: قاعدة الوحدة إن **ولا ملف
     *    خزنة يذكر `custody_rep_id`** (يحرسها `bin/verify_rep_bill.php`)، فأي
     *    تعديل على الوسم يفضل في مكان واحد. الاستعمال:
     *
     *        "SELECT b.id" . RepBillCustody::payerSelectSql() . "
     *         FROM bawalis b" . RepBillCustody::payerJoinSql()
     *
     * بيرجّعوا عمودًا فاضيًا/جوين فاضي قبل الترحيل، فالاستعلام يفضل صالحًا.
     */
    public static function payerSelectSql(string $repAlias = 'cr'): string
    {
        $r = rtrim($repAlias, '.');
        return self::on() ? ", $r.name AS custody_rep_name" : ", NULL AS custody_rep_name";
    }

    /** الجوين المقابل لـ`payerSelectSql()` — لازم الاتنين مع بعض. */
    public static function payerJoinSql(string $alias = 'b', string $repAlias = 'cr'): string
    {
        if (!self::on()) { return ''; }
        $a = rtrim($alias, '.'); $r = rtrim($repAlias, '.');
        return " LEFT JOIN reps $r ON $r.id = $a.custody_rep_id";
    }

    // ───────────────────────────────────── ② جانب عهدة المندوب (الإضافة)

    /**
     * إجمالي عهد البوالص اللي دفعها المندوب من محفظته.
     * $day = يوم واحد بالظبط (عهدة اليوم — v49)، أو null لكل الأيام (التراكمي).
     */
    public static function totalFor(int $repId, ?string $day = null): float
    {
        if (!self::on()) { return 0.0; }
        [$ded, $dp] = Custody::deductionSql('custody', 'car_no', 'bill_date');
        $args = $dp; $args[] = $repId; $w = '';
        if ($day !== null && $day !== '') { $w = ' AND bill_date = ?'; $args[] = $day; }
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM($ded),0) FROM bawalis
             WHERE custody_rep_id = ? AND is_legacy = 0$w" . RepCustody::epochSql('bill_date'), $args), 2);
    }

    /** [تاريخ => إجمالي] لمندوب واحد — لكشف «عهدة كل يوم» (v49). */
    public static function totalsByDay(int $repId): array
    {
        if (!self::on()) { return []; }
        $out = [];
        [$ded, $dp] = Custody::deductionSql('custody', 'car_no', 'bill_date');
        foreach (Database::all(
            "SELECT bill_date d, SUM($ded) total FROM bawalis
             WHERE custody_rep_id = ? AND is_legacy = 0" . RepCustody::epochSql('bill_date')
             . " GROUP BY bill_date", array_merge($dp, [$repId])) as $r) {
            $out[(string) $r['d']] = round((float) $r['total'], 2);
        }
        return $out;
    }

    /** [rep_id => إجمالي] — لكروت العهدة بلا استعلام لكل مندوب. */
    public static function totalsByRep(?string $day = null): array
    {
        if (!self::on()) { return []; }
        [$ded, $dp] = Custody::deductionSql('custody', 'car_no', 'bill_date');
        $args = $dp; $w = '';
        if ($day !== null && $day !== '') { $w = ' AND bill_date = ?'; $args[] = $day; }
        $out = [];
        foreach (Database::all(
            "SELECT custody_rep_id rid, SUM($ded) total FROM bawalis
             WHERE custody_rep_id IS NOT NULL AND is_legacy = 0$w" . RepCustody::epochSql('bill_date')
             . " GROUP BY custody_rep_id", $args) as $r) {
            $out[(int) $r['rid']] = round((float) $r['total'], 2);
        }
        return $out;
    }

    /** إجمالي كل المناديب (لبطاقة «برّه مع المناديب»). */
    public static function totalAll(): float
    {
        if (!self::on()) { return 0.0; }
        [$ded, $dp] = Custody::deductionSql('custody', 'car_no', 'bill_date');
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM($ded),0) FROM bawalis
             WHERE custody_rep_id IS NOT NULL AND is_legacy = 0" . RepCustody::epochSql('bill_date'), $dp), 2);
    }

    /** بوالص مندوب (الأحدث أولاً) + بيانات الإذن — لكشف الصرف. */
    public static function forRep(int $repId, int $limit = 0, ?string $day = null): array
    {
        if (!self::on()) { return []; }
        $lim = $limit > 0 ? ' LIMIT ' . (int) $limit : '';
        $where = 'b.custody_rep_id = ? AND b.is_legacy = 0'; $params = [$repId];
        if ($day !== null && $day !== '') { $where .= ' AND b.bill_date = ?'; $params[] = $day; }
        return Database::all(
            "SELECT b.id, b.bill_number, b.bill_date, b.driver, b.car_no, b.custody,
                    b.container_no, b.container_type, b.container_count, b.destination,
                    b.permit_id, p.permit_no, p.permit_type, p.company, p.client
             FROM bawalis b
             LEFT JOIN permits p ON p.id = b.permit_id
             WHERE $where" . RepCustody::epochSql('b.bill_date') . "
             ORDER BY b.bill_date DESC, b.id DESC$lim", $params);
    }

    /**
     * هل عهدة البوليصة دي مدفوعة من محفظة مندوب؟
     *
     * ⚠️ موجودة عشان **حتى الفحص على مستوى PHP** يمرّ من هنا: `Custody::capError`
     *    محتاج يعرف الإجابة عشان يتخطّى سقف الخزنة، ولو قرا العمود بإيده يبقى
     *    فيه تعريفان لنفس السؤال. القاعدة: مفيش ملف خزنة بيذكر `custody_rep_id`.
     */
    public static function billIsRepPaid(?int $billId): bool
    {
        if (!self::on() || !$billId) { return false; }
        return (bool) Database::scalar(
            'SELECT custody_rep_id FROM bawalis WHERE id = ?', [$billId]);
    }

    /**
     * ⚠️⚠️ **المصدر الوحيد لسؤال «ينفع أوسم البوليصة دي لمندوب؟»** — بيرجّع سببًا
     *    عربيًّا للرفض أو `null` لو مسموح.
     *
     *    ليه لازم يتنادى في **كل** مسار كتابة (فورم الإذن · `/bawalis` · موبايل
     *    المندوب): الوسم بيعمل حاجتين لازم يمشوا مع بعض — يستثني البوليصة من
     *    الخزنة **و** يضيفها لمحفظة المندوب. بس جانب المحفظة بيمرّ على
     *    `RepCustody::epochSql('bill_date')` (خط الفصل) وجانب الخزنة **لأ**.
     *    فبوليصة تاريخها **قبل** خط الفصل لو اتوسمت: بتختفي من الخزنة وما
     *    بتظهرش في أي محفظة ⇒ **فلوس بتتبخّر من النظام بلا أي خطأ ظاهر**
     *    (نفس تحذير v45 عن بنود الفيزا بالحرف).
     *
     *    عهد ما قبل خط الفصل متحسوبة على الخزنة خلاص (والأيام دي متقفلة)،
     *    فنقلها لمندوب مالوش معنى محاسبي أصلاً.
     */
    public static function payerBlock(?string $billDate): ?string
    {
        if (!self::on()) {
            return 'وسم دافع العهدة مش متفعّل — شغّل migrate_v60 الأول.';
        }
        $epoch = RepCustody::epoch();
        $d = trim((string) $billDate);
        if ($epoch !== '' && $d !== '' && $d < $epoch) {
            return 'البوليصة دي تاريخها ' . dfmt($d) . ' — يعني قبل بداية النظام الجديد ('
                 . dfmt($epoch) . '), وعهدتها متحسوبة على خزنة المكتب خلاص. '
                 . 'لو وسمناها لمندوب هتختفي من الخزنة وما تظهرش في محفظته. كلّم المكتب.';
        }
        return null;
    }

    /** اختصار: هل مسموح أوسم بوليصة بالتاريخ ده؟ (نفس مصدر `payerBlock`) */
    public static function payerAllowed(?string $billDate): bool
    {
        return self::payerBlock($billDate) === null;
    }

    /** بوليصة موسومة لمندوب معيّن (للتأكد قبل الحذف من موبايله). */
    public static function findMine(int $repId, int $billId): ?array
    {
        if (!self::on()) { return null; }
        return Database::row(
            "SELECT * FROM bawalis WHERE id = ? AND custody_rep_id = ? AND is_legacy = 0",
            [$billId, $repId]);
    }
}
