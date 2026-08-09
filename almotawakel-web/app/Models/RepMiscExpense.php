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
 *
 * ⚠️ **الوسم (v71):** بقى لكل نثرية نوع (`misc_kind`) بيختاره المندوب وقت
 *    الإدخال — سلفة · مواصلات داخلية · مواصلات خارجية · عامة. الكتالوج كله في
 *    `RepMiscKind`، و`category` بيتولّد منه فالعرض في `/expenses` وكشف حساب
 *    المندوب بيقول النوع بلا أي تعديل في الشاشتين.
 *    **الوسم ما غيّرش أي معاملة مالية**: السلفة لسه مصروف شركة بيتخصم من
 *    الخزنة ومن العهدة زي أي نثرية. تحويلها لمستحق على المندوب قرار مالي
 *    جديد بتاع وحدة الرواتب — ساعتها الطريق الصح استثناؤها من `cashOnlySql()`.
 */
class RepMiscExpense
{
    /**
     * تصنيف العرض الافتراضي في سجل المصروفات.
     * ⚠️ لازم يفضل مطابقًا لـ`RepMiscKind::category('general')` — `verify_v71`
     *    بيفشل لو اتفرّقوا، وإلا النثرية العامة تتعرض باسمين حسب مين كتب الصف.
     */
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
        /* ⚠️ الوسم (v71) بيتطبّع هنا كمان مش في المستدعي بس — الموديل هو الحارس
           والفورم مش مصدر ثقة (قاعدة ③). وأي قيمة مجهولة بتقع على `general`،
           فمستحيل يدخل الجدول وسم برّه الكتالوج مهما كان مصدر النداء. */
        $kind = RepMiscKind::normalize($d['misc_kind'] ?? null);
        /* ⚠️ السلفة كاش دايمًا: كارت المكتب ما بينزّلش كاش لجيب المندوب، فالوسم
           ده بيلغي الفيزا هنا برضه مش في التحقّق بس. */
        $isVisa = (!empty($d['is_visa']) && !RepMiscKind::isCashOnly($kind)) ? 1 : 0;
        /* التصنيف المعروض بيتولّد من الوسم — المستدعي ما بيكتبوش بإيده، وإلا
           بقى فيه تعريفان للاسم ممكن يختلفوا (قاعدة ②). */
        $category = RepMiscKind::category($kind);

        $kindReady = self::kindReady();
        $cols = 'rep_id, submission_id, exp_date, category, note, qty, unit_amount,
                 amount, ref_no, attachment, is_visa, visa_card_id, created_by'
              . ($kindReady ? ', misc_kind' : '');
        $args = [
            (int) $d['rep_id'],
            !empty($d['submission_id']) ? (int) $d['submission_id'] : null,
            $d['exp_date'],
            $category,
            (string) $d['note'],
            $qty,
            $unit,
            round($unit * $qty, 2),
            ($d['ref_no'] ?? '') !== '' ? $d['ref_no'] : null,
            ($d['attachment'] ?? '') !== '' ? $d['attachment'] : null,
            $isVisa,
            $isVisa && !empty($d['visa_card_id']) ? (int) $d['visa_card_id'] : null,
            Auth::user(),
        ];
        if ($kindReady) { $args[] = $kind; }

        Database::exec(
            "INSERT INTO rep_misc_expenses ($cols)
             VALUES (" . implode(',', array_fill(0, count($args), '?')) . ")",
            $args
        );
        return Database::lastId();
    }

    /**
     * ⚠️ **خاصية ساكنة على الكلاس** مش `static` جوّه الدالة — دي الصيغة الوحيدة
     *    اللي `refreshKindReady()` تقدر تصفّرها (مصيدة v57 الموثَّقة).
     */
    private static ?bool $kindReady = null;

    /**
     * هل عمود الوسم اترحّل؟ (migrate_v71) — قبله النظام بيفضل بسلوكه القديم
     * بالحرف: بيكتب بلا وسم، والقراءة بتعتبر كل نثرية `general`.
     *
     * ⚠️ بنسأل عن **العمود** مباشرةً مش عن الجدول الأول: العمود ما يوجدش إلا
     *    والجدول موجود، وكده الدالة مش مربوطة بكاش `available()` اللي مفيش
     *    طريقة تصفّره من برّه.
     */
    public static function kindReady(): bool
    {
        if (self::$kindReady === null) {
            self::$kindReady = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = 'rep_misc_expenses'
                    AND column_name = 'misc_kind'");
        }
        return self::$kindReady;
    }

    /** ⚠️ إلزامية في `migrate_v71` — الكاش بيتقرا قبل الـALTER فيفضل false بعده. */
    public static function refreshKindReady(): bool
    {
        self::$kindReady = null;
        return self::kindReady();
    }

    /**
     * ⚠️ شرط «النثرية الكاش» (v45) — المصدر الوحيد له.
     *
     * النثرية المدفوعة بكارت الفيزا بتتخصم من **الكارت وبس**: لا من الخزنة ولا
     * من عهدة المندوب. فأي حساب كاش لازم يمرّ على الشرط ده بدل ما يكتبه بإيده.
     * بيرجع '1' قبل الترحيل، فالنظام يفضل بسلوكه القديم بدل ما يكسر.
     *
     * بيستخدمه: Closing::sumRepMisc/hasMovementBetween/earliestMovementDate ·
     * DailyReview · DailyClosingController · RepMiscExpense::total* ·
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
     *
     * $day (v49): يوم واحد بالظبط = نثريات عهدة اليوم ده بس. null = كل الأيام
     * (السلوك القديم بالحرف). العهدة اليومية بتتحسب من نفس الصف ونفس شرط الكاش
     * — مفيش تعريف موازٍ ممكن يختلف.
     */
    public static function totalFor(int $repId, ?string $day = null): float
    {
        if (!self::available()) { return 0.0; }
        $args = [$repId];
        $dayW = '';
        if ($day !== null && $day !== '') { $dayW = ' AND exp_date = ?'; $args[] = $day; }
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM rep_misc_expenses
             WHERE rep_id = ? AND " . self::cashOnlySql() . $dayW
             . RepCustody::epochSql('exp_date'), $args), 2);
    }

    /** [تاريخ => إجمالي كاش] لمندوب واحد — لكشف «عهدة كل يوم» (v49). */
    public static function totalsByDay(int $repId): array
    {
        if (!self::available()) { return []; }
        $out = [];
        foreach (Database::all(
            "SELECT exp_date d, SUM(amount) total FROM rep_misc_expenses
             WHERE rep_id = ? AND " . self::cashOnlySql() . RepCustody::epochSql('exp_date')
             . " GROUP BY exp_date", [$repId]) as $r) {
            $out[(string) $r['d']] = round((float) $r['total'], 2);
        }
        return $out;
    }

    /** إجمالي نثريات كل المناديب الكاش (لبطاقة الملخّص). */
    public static function totalAll(): float
    {
        if (!self::available()) { return 0.0; }
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM rep_misc_expenses WHERE " . self::cashOnlySql()
            . RepCustody::epochSql('exp_date')), 2);
    }

    /**
     * [rep_id => إجمالي كاش] — لصفحة كروت العهدة بلا استعلام لكل مندوب.
     * $day (v49): يوم واحد بالظبط، أو null لكل الأيام (السلوك القديم).
     */
    public static function totalsByRep(?string $day = null): array
    {
        if (!self::available()) { return []; }
        $args = []; $dayW = '';
        if ($day !== null && $day !== '') { $dayW = ' AND exp_date = ?'; $args[] = $day; }
        $out = [];
        foreach (Database::all(
            "SELECT rep_id, SUM(amount) total FROM rep_misc_expenses
             WHERE " . self::cashOnlySql() . $dayW . RepCustody::epochSql('exp_date')
             . " GROUP BY rep_id", $args) as $r) {
            $out[(int) $r['rep_id']] = round((float) $r['total'], 2);
        }
        return $out;
    }

    // ─────────────────────────────────────────────── الوسم (v71)

    /**
     * ⚠️ **المصدر الوحيد** لشرط «النثرية دي من النوع الفلاني» في SQL.
     * بيتعامل مع الصف القديم (NULL) كأنه `general` عبر `RepMiscKind::sqlKey()`،
     * فمفيش صف بيقع برّه أي فلتر. وبيرجع `'1'` قبل الترحيل (كل النثريات نوع
     * واحد ساعتها) عشان الاستعلام يفضل صالحًا بدل ما يكسر.
     *
     * ⚠️ ممنوع أي ملف يكتب `misc_kind = 'advance'` بإيده — `verify_v71` بيمسكها.
     */
    public static function kindSql(string $kind, string $alias = ''): string
    {
        if (!self::kindReady()) {
            return RepMiscKind::normalize($kind) === RepMiscKind::DEFAULT ? '1' : '0';
        }
        return RepMiscKind::sqlKey($alias) . ' = ' . RepMiscKind::sqlLiteral($kind);
    }

    /**
     * [وسم => إجمالي كاش] لمندوب واحد أو لكل المناديب، في فترة اختيارية.
     * الأساس اللي وحدة الرواتب ومصروف المناديب الأسبوعي هيقروا منه (v71).
     *
     * ⚠️ **الكاش بس** زي كل حسابات العهدة: المدفوع بكارت المكتب خرج من الكارت
     *    مش من جيب المندوب، فما ينفعش يتخصم من راتبه ولا من مصروف الأسبوع.
     * كل مفاتيح الكتالوج بترجع حتى لو صفر، فالشاشة ما تحتاجش تعرف الكتالوج.
     *
     * @return array<string,float>
     */
    public static function totalsByKind(int $repId = 0, string $from = '', string $to = ''): array
    {
        $out = array_fill_keys(RepMiscKind::keys(), 0.0);
        if (!self::available()) { return $out; }

        $args = []; $w = '';
        if ($repId > 0)  { $w .= ' AND rep_id = ?';   $args[] = $repId; }
        if ($from !== '') { $w .= ' AND exp_date >= ?'; $args[] = $from; }
        if ($to !== '')   { $w .= ' AND exp_date <= ?'; $args[] = $to; }

        $key = self::kindReady() ? RepMiscKind::sqlKey() : RepMiscKind::sqlLiteral(RepMiscKind::DEFAULT);
        foreach (Database::all(
            "SELECT $key AS k, SUM(amount) total FROM rep_misc_expenses
              WHERE " . self::cashOnlySql() . $w . RepCustody::epochSql('exp_date')
            . " GROUP BY k", $args) as $r) {
            $k = RepMiscKind::normalize((string) $r['k']);
            $out[$k] = round($out[$k] + (float) $r['total'], 2);
        }
        return $out;
    }

    /**
     * [rep_id => إجمالي كاش] لنوع واحد في فترة — كارت «سلف المناديب» أو
     * «مواصلات الأسبوع» بيقرا منها بلا استعلام لكل مندوب.
     *
     * @return array<int,float>
     */
    public static function totalsByRepForKind(string $kind, string $from = '', string $to = ''): array
    {
        if (!self::available()) { return []; }
        $args = []; $w = '';
        if ($from !== '') { $w .= ' AND exp_date >= ?'; $args[] = $from; }
        if ($to !== '')   { $w .= ' AND exp_date <= ?'; $args[] = $to; }

        $out = [];
        foreach (Database::all(
            "SELECT rep_id, SUM(amount) total FROM rep_misc_expenses
              WHERE " . self::cashOnlySql() . ' AND ' . self::kindSql($kind) . $w
            . RepCustody::epochSql('exp_date') . ' GROUP BY rep_id', $args) as $r) {
            $out[(int) $r['rep_id']] = round((float) $r['total'], 2);
        }
        return $out;
    }

    /** نثريات مندوب (الأحدث أولاً) — لكشف حسابه. */
    public static function forRep(int $repId): array
    {
        if (!self::available()) { return []; }
        return Database::all(
            "SELECT * FROM rep_misc_expenses WHERE rep_id = ?" . RepCustody::epochSql('exp_date') . "
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
                   x.amount AS amount, x.note AS note, $visa AS is_visa, 0 AS unpaid
            FROM rep_misc_expenses x
            JOIN reps r ON r.id = x.rep_id
            WHERE x.amount <> 0
        ";
    }
}
