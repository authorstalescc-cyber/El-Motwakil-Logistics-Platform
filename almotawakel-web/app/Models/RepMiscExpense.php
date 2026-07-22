<?php
namespace App\Models;

use App\Core\Auth;
use App\Core\Database;

/**
 * نثريات المناديب (v39) — المصروف العام اللي المندوب بيدفعه من عهدته **بلا إذن**.
 *
 * مواصلات، شاي، تصوير مستندات، إكرامية… حاجات مش مربوطة بإذن معيّن فمينفعش
 * تتحط على تايم لاين أي إذن ولا على حساب أي عميل.
 *
 * ⚠️ **قرار المالك:** النثرية بتروح **للمصروفات وبس** — عمرها ما تدخل حساب
 *    عميل ولا فاتورة إذن. عشان كده مفيش فيها `actual_amount` أصلاً: مفيش
 *    «سعر فعلي للعميل» لأنها مش هتتحاسب على حد.
 *
 * ⚠️ **قرار المالك v42:** النثرية مصروف شركة نقدي؛ تُخصم من الخزنة بتاريخ الصرف
 *    وتدخل التقفيل والمطابقة، كما تُخصم من عهدة المندوب. تنزيل العهدة نفسه لا
 *    يغيّر الخزنة، والخصم الوحيد يحدث عند اعتماد المصروف.
 *
 *    بتظهر في:   `/expenses` (مصدر `rep_misc`) + رصيد عهدة المندوب (بتتخصم منه)
 *    ولا تظهر في: أي فاتورة عميل أو تايم لاين إذن.
 */
class RepMiscExpense
{
    /** تصنيف العرض الافتراضي في سجل المصروفات. */
    public const CATEGORY = 'نثريات';

    /** هل الجدول موجود؟ (يخلّي النظام يشتغل عادي قبل migrate_v39) */
    public static function available(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $ok = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'rep_misc_expenses'"
            );
        }
        return $ok;
    }

    // ─────────────────────────────────────────────── الكتابة

    /**
     * إنشاء نثرية معتمدة. بيتنادى من RepInbox::approve بس (بعد اعتماد المالك).
     *
     * `RepInbox::approve()` ينفّذ `Closing::guard` داخل قفل الاعتماد قبل الوصول
     * إلى هنا، لأن النثرية تغيّر خزنة يوم الصرف والتقفيل المثبّت.
     */
    public static function create(array $d): int
    {
        $qty  = max(1, (int) ($d['qty'] ?? 1));
        $unit = round((float) ($d['unit_amount'] ?? 0), 2);
        $isVisa = !empty($d['is_visa']) ? 1 : 0;
        Database::exec(
            "INSERT INTO rep_misc_expenses
                (rep_id, submission_id, exp_date, category, note, qty, unit_amount,
                 amount, ref_no, attachment, is_visa, visa_card_id, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                (int) $d['rep_id'],
                !empty($d['submission_id']) ? (int) $d['submission_id'] : null,
                $d['exp_date'],
                trim((string) ($d['category'] ?? '')) !== '' ? $d['category'] : self::CATEGORY,
                (string) $d['note'],
                $qty,
                $unit,
                round($unit * $qty, 2),
                ($d['ref_no'] ?? '') !== '' ? $d['ref_no'] : null,
                ($d['attachment'] ?? '') !== '' ? $d['attachment'] : null,
                $isVisa,
                $isVisa && !empty($d['visa_card_id']) ? (int) $d['visa_card_id'] : null,
                Auth::user(),
            ]
        );
        return Database::lastId();
    }

    /**
     * ⚠️ شرط «النثرية الكاش» (v45) — المصدر الوحيد له.
     *
     * النثرية المدفوعة بكارت الفيزا بتتخصم من **الكارت وبس**: لا من الخزنة ولا
     * من عهدة المندوب. فأي حساب كاش لازم يمرّ على الشرط ده بدل ما يكتبه بإيده.
     * بيرجع '1' قبل الترحيل، فالنظام يفضل بسلوكه القديم بدل ما يكسر.
     *
     * بيستخدمه: Closing::sumRepMisc/hasMovementBetween/earliestMovementDate ·
     * DailyReconciliation · DailyClosingController · RepMiscExpense::total* ·
     * bin/backfill_closings.php
     */
    public static function cashOnlySql(string $alias = ''): string
    {
        if (!self::visaReady()) { return '1'; }
        $p = $alias !== '' ? $alias . '.' : '';
        return "{$p}is_visa = 0";
    }

    /** هل عمودا الفيزا اترحّلوا؟ (migrate_v45) */
    public static function visaReady(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $ok = self::available() && (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'rep_misc_expenses'
                   AND column_name = 'is_visa'"
            );
        }
        return $ok;
    }

    public static function find(int $id): ?array
    {
        if (!self::available()) { return null; }
        return Database::row("SELECT * FROM rep_misc_expenses WHERE id = ?", [$id]);
    }

    public static function delete(int $id): void
    {
        // الأدمن له حرية الحذف (قرار المالك): بنفكّ الربط وسجل الاعتماد بيفضل محفوظ.
        DeletionPolicy::detachMisc($id);
        Database::exec("DELETE FROM rep_misc_expenses WHERE id = ?", [$id]);
    }

    // ─────────────────────────────────────────────── القراءة

    /**
     * إجمالي نثريات مندوب **الكاش بس** — بيتخصم من عهدته (دفعها من فلوسه النازلة).
     * المدفوع بالفيزا مستثنى: خرج من كارت المكتب مش من جيبه (v45).
     */
    public static function totalFor(int $repId): float
    {
        if (!self::available()) { return 0.0; }
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM rep_misc_expenses
             WHERE rep_id = ? AND " . self::cashOnlySql(), [$repId]), 2);
    }

    /** إجمالي نثريات كل المناديب الكاش (لبطاقة الملخّص). */
    public static function totalAll(): float
    {
        if (!self::available()) { return 0.0; }
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM rep_misc_expenses WHERE " . self::cashOnlySql()), 2);
    }

    /** [rep_id => إجمالي كاش] — لصفحة كروت العهدة بلا استعلام لكل مندوب. */
    public static function totalsByRep(): array
    {
        if (!self::available()) { return []; }
        $out = [];
        foreach (Database::all(
            "SELECT rep_id, SUM(amount) total FROM rep_misc_expenses
             WHERE " . self::cashOnlySql() . " GROUP BY rep_id") as $r) {
            $out[(int) $r['rep_id']] = round((float) $r['total'], 2);
        }
        return $out;
    }

    /** نثريات مندوب (الأحدث أولاً) — لكشف حسابه. */
    public static function forRep(int $repId): array
    {
        if (!self::available()) { return []; }
        return Database::all(
            "SELECT * FROM rep_misc_expenses WHERE rep_id = ?
             ORDER BY exp_date DESC, id DESC", [$repId]);
    }

    /** إجمالي نثريات يوم واحد الكاش (للوحة المتابعة) — عرض فقط. */
    public static function sumOn(string $day): float
    {
        if (!self::available()) { return 0.0; }
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM rep_misc_expenses
             WHERE exp_date = ? AND " . self::cashOnlySql(), [$day]), 2);
    }

    /**
     * جزء الاتحاد في سجل المصروفات `/expenses` (مصدر `rep_misc`).
     * بيرجع '' لو الجدول لسه مش موجود، فـExpense بتتخطّاه بأمان قبل الترحيل.
     *
     * ⚠️ v45: `is_visa` بقى العمود الحقيقي بدل الصفر الثابت. النثرية الكاش
     *    بتتخصم من الخزنة في تاريخها (Closing بيقرا الجدول مباشرة)، والمدفوعة
     *    بالفيزا بتتخصم من الكارت وبس وبتظهر هنا موسومة زي أي مصروف فيزا.
     */
    public static function expenseUnionSql(): string
    {
        if (!self::available()) { return ''; }
        $visa = self::visaReady() ? 'x.is_visa' : '0';
        return "
            SELECT 'rep_misc' AS source, x.rep_id AS ref_id, x.exp_date AS d,
                   COALESCE(NULLIF(x.category,''),'" . self::CATEGORY . "') AS category,
                   COALESCE(NULLIF(r.name,''),'') AS party,
                   x.amount AS amount, x.note AS note, $visa AS is_visa
            FROM rep_misc_expenses x
            JOIN reps r ON r.id = x.rep_id
            WHERE x.amount <> 0
        ";
    }
}
