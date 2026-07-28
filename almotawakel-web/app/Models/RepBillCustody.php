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
 * ⚠️ **المخصوم من المندوب = العهدة كاملة، مش «العهدة − 100»** (`Custody::
 *    billDeduction`). الـ100 قاعدة محاسبية بتخصّ خزنة المكتب، والمندوب سلّم
 *    السائق المبلغ كامل من جيبه. ومحفظة المندوب معزولة تمامًا عن الخزنة (v35)
 *    فمفيش مطابقة مطلوبة بين الرقمين.
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
     */
    public static function officeExcludeSql(string $alias = ''): string
    {
        if (!self::on()) { return ''; }
        $a = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        return " AND {$a}custody_rep_id IS NULL";
    }

    // ───────────────────────────────────── ② جانب عهدة المندوب (الإضافة)

    /**
     * إجمالي عهد البوالص اللي دفعها المندوب من محفظته.
     * $day = يوم واحد بالظبط (عهدة اليوم — v49)، أو null لكل الأيام (التراكمي).
     */
    public static function totalFor(int $repId, ?string $day = null): float
    {
        if (!self::on()) { return 0.0; }
        $args = [$repId]; $w = '';
        if ($day !== null && $day !== '') { $w = ' AND bill_date = ?'; $args[] = $day; }
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(custody),0) FROM bawalis
             WHERE custody_rep_id = ? AND is_legacy = 0$w", $args), 2);
    }

    /** [تاريخ => إجمالي] لمندوب واحد — لكشف «عهدة كل يوم» (v49). */
    public static function totalsByDay(int $repId): array
    {
        if (!self::on()) { return []; }
        $out = [];
        foreach (Database::all(
            "SELECT bill_date d, SUM(custody) total FROM bawalis
             WHERE custody_rep_id = ? AND is_legacy = 0 GROUP BY bill_date", [$repId]) as $r) {
            $out[(string) $r['d']] = round((float) $r['total'], 2);
        }
        return $out;
    }

    /** [rep_id => إجمالي] — لكروت العهدة بلا استعلام لكل مندوب. */
    public static function totalsByRep(?string $day = null): array
    {
        if (!self::on()) { return []; }
        $args = []; $w = '';
        if ($day !== null && $day !== '') { $w = ' AND bill_date = ?'; $args[] = $day; }
        $out = [];
        foreach (Database::all(
            "SELECT custody_rep_id rid, SUM(custody) total FROM bawalis
             WHERE custody_rep_id IS NOT NULL AND is_legacy = 0$w GROUP BY custody_rep_id", $args) as $r) {
            $out[(int) $r['rid']] = round((float) $r['total'], 2);
        }
        return $out;
    }

    /** إجمالي كل المناديب (لبطاقة «برّه مع المناديب»). */
    public static function totalAll(): float
    {
        if (!self::on()) { return 0.0; }
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(custody),0) FROM bawalis
             WHERE custody_rep_id IS NOT NULL AND is_legacy = 0"), 2);
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
             WHERE $where
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

    /** بوليصة موسومة لمندوب معيّن (للتأكد قبل الحذف من موبايله). */
    public static function findMine(int $repId, int $billId): ?array
    {
        if (!self::on()) { return null; }
        return Database::row(
            "SELECT * FROM bawalis WHERE id = ? AND custody_rep_id = ? AND is_legacy = 0",
            [$billId, $repId]);
    }
}
