<?php
namespace App\Models;

use App\Core\App;
use App\Core\Auth;
use App\Core\Database;

/**
 * الإقفال اليومي والترحيل (day-end close / carry-forward) — «زي البرامج الرائدة».
 *
 * الفكرة: آخر كل يوم تُثبَّت قيمة نهائية (closing_balance) تصبح «رصيد بداية بكرة».
 * الأرقام تتجمّد، والرصيد الحالي يُبنى على آخر إقفال بدل إعادة حساب كل التاريخ.
 *
 *   الرصيد المرحّل لليوم = opening + عهد مضافة − مصروفات − عهد سائقين (بعد خصم 100).
 *   opening = closing_balance لآخر يوم متقفل قبله (أو الرصيد الافتتاحي من الإعدادات).
 *   رصيد العهدة الحالي = closing_balance لآخر يوم متقفل + كل الحركة بتاريخ بعده.
 *
 * ملاحظات جوهرية:
 *  • بعكس محفظة العهدة القديمة، هذا الحساب **يخصم المصروفات** (بنود + يدوية، بلا فيزا).
 *  • «وجود صف لتاريخ في daily_closings = ذلك اليوم متقفل». الفتح يحذف الصف.
 *  • الإقفال تسلسلي من الأقدم (بلا فجوات)، والفتح تسلسلي من الأحدث.
 *  • خصم عهدة كل بوليصة يتّبع نفس قاعدة App\Models\Custody (طرح 100 + استثناء السيارات).
 *  • مصادر الحركة الثلاثة: manual_custody (عهد مضافة)، permit_stages+manual_expenses
 *    (مصروفات)، bawalis.custody (عهد سائقين) — بنفس تعريفات التقفيل اليومي.
 */
class Closing
{
    private static ?bool $tableAvailable = null;
    private static ?string $freeRefreshFrom = null;
    private static bool $freeRefreshRegistered = false;

    /** هل جدول الإقفالات موجود؟ (يسمح بعمل النظام حتى قبل تشغيل migrate_v18) */
    private static function available(): bool
    {
        if (self::$tableAvailable === null) {
            self::$tableAvailable = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'daily_closings'"
            );
        }
        return self::$tableAvailable;
    }

    // ───────────────────────────────────────────── أدوات شرط التاريخ

    /**
     * يبني شرط SQL على عمود تاريخ. الأولوية لـ $eq (مساواة يوم واحد).
     * وإلا نطاق: ($gt أصغر حدّ حصري أو شامل حسب $gte) و ($lt حدّ أعلى حصري).
     * $gt=null و $lt=null و $eq=null → '1' (كل السجلات).
     */
    private static function whereDate(string $col, ?string $gt, bool $gte, ?string $lt, ?string $eq): array
    {
        if ($eq !== null) { return ["$col = ?", [$eq]]; }
        $parts = []; $p = [];
        if ($gt !== null) { $parts[] = "$col " . ($gte ? '>=' : '>') . " ?"; $p[] = $gt; }
        if ($lt !== null) { $parts[] = "$col < ?"; $p[] = $lt; }
        return [$parts ? implode(' AND ', $parts) : '1', $p];
    }

    // ───────────────────────────────────────────── مجاميع الحركة (بشرط تاريخ مرن)

    /** إجمالي العهد المضافة (توب-أب) في نطاق/يوم. */
    private static function sumTopups(?string $gt, bool $gte, ?string $lt, ?string $eq = null): float
    {
        [$w, $p] = self::whereDate('cust_date', $gt, $gte, $lt, $eq);
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM manual_custody WHERE $w", $p), 2);
    }

    /**
     * إجمالي المصروفات النقدية (بلا فيزا) في نطاق/يوم:
     *   بنود الإصدارات + المصاريف اليدوية + كاش المناديب (سُلف + دفعات تسوية).
     * كاش المناديب هو الوحيد اللي بيطلّع فلوس من الخزنة من وحدة المناديب — لازم يمشي
     * مع Expense (مصدر reps) و Rep::CASH_KINDS وإلا العهدة تبوظ.
     *
     * ⚠️ استثناء واحد من بنود الإصدارات — is_visa = 0: المدفوع بالفيزا بيتخصم
     *    من كارت الفيزا (v22) لأن الفيزا **بنك**، فلوس تانية خالص مش من الدرج.
     *
     * ⚠️ **v42 — بنود عهدة المناديب بقت تتخصم هنا** (كانت مستثناة في v35):
     *    الفلوس اللي بتنزل للمندوب بتطلع من الدرج بإيد المالك، فنسخ نموذج كروت
     *    الفيزا (اللي بيستثني) كان بيسيب فتحة: لا التنزيل ولا الصرف بيتخصم، فالخزنة
     *    تفضل مضخّمة بقيمة اللي في جيب المناديب. القاعدة الجديدة:
     *      • تنزيل العهدة للمندوب  → مالوش أثر على الخزنة (تحويل داخلي).
     *      • صرف المندوب (اعتماده) → بيتخصم من الخزنة يوم الصرف زي أي مصروف.
     *      • مرتجع العهدة          → مالوش أثر (الفلوس أصلاً ماخرجتش وقت التنزيل).
     *    فعلى مدار الدورة الكاملة الخزنة بتنقص بالظبط اللي اتصرف فعلاً.
     *    عهدة المندوب نفسها فضلت محفظة منفصلة (RepCustody::balance) زي ما هي.
     */
    private static function sumExpenses(?string $gt, bool $gte, ?string $lt, ?string $eq = null): float
    {
        $expSql = Permit::expenseSql('s');
        [$w1, $p1] = self::whereDate('s.entry_date', $gt, $gte, $lt, $eq);
        $stage = (float) Database::scalar(
            "SELECT COALESCE(SUM(s.amount),0) FROM permit_stages s
             WHERE $expSql AND s.is_visa = 0 AND $w1", $p1);
        [$w2, $p2] = self::whereDate('exp_date', $gt, $gte, $lt, $eq);
        $manual = (float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM manual_expenses WHERE is_visa = 0 AND $w2", $p2);
        $reps = self::sumRepCash($gt, $gte, $lt, $eq);
        $misc = self::sumRepMisc($gt, $gte, $lt, $eq);
        return round($stage + $manual + $reps + $misc, 2);
    }

    /**
     * إجمالي نثريات المناديب (v39) في نطاق/يوم — كاش خرج من الخزنة عبر عهدة المندوب.
     * بترجع 0 قبل migrate_v39 فالنظام يفضل يشتغل بسلوكه القديم بدل ما يكسر.
     *
     * ⚠️ v45: **الكاش بس** — النثرية المدفوعة بالفيزا خرجت من كارت المكتب مش من
     *    الخزنة، فبتتستثنى هنا زي أي بند `is_visa=1` في باقي المصادر.
     *    الشرط من `RepMiscExpense::cashOnlySql()` — ممنوع تكتبه بإيدك.
     */
    private static function sumRepMisc(?string $gt, bool $gte, ?string $lt, ?string $eq = null): float
    {
        if (!self::tableExists('rep_misc_expenses')) { return 0.0; }
        [$w, $p] = self::whereDate('exp_date', $gt, $gte, $lt, $eq);
        $cash = RepMiscExpense::cashOnlySql();
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM rep_misc_expenses WHERE $cash AND $w", $p), 2);
    }

    /** إجمالي كاش المناديب النقدي (سُلف + دفعات تسوية، بلا فيزا) في نطاق/يوم. */
    private static function sumRepCash(?string $gt, bool $gte, ?string $lt, ?string $eq = null): float
    {
        if (!self::tableExists('rep_entries')) { return 0.0; }
        $cash = Rep::cashKindsList();
        [$w, $p] = self::whereDate('entry_date', $gt, $gte, $lt, $eq);
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM rep_entries
             WHERE kind IN ($cash) AND is_visa = 0 AND $w", $p), 2);
    }

    /** هل الجدول موجود؟ (يسمح بعمل التقفيل قبل migrate_v21 من غير كسر). */
    private static function tableExists(string $name): bool
    {
        static $cache = [];
        if (!isset($cache[$name])) {
            $cache[$name] = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?", [$name]);
        }
        return $cache[$name];
    }

    /** إجمالي عهد السائقين المخصومة (بعد طرح 100 لكل بوليصة) في نطاق/يوم؛ يمكن استثناء بوليصة. */
    private static function sumDriverCustody(?string $gt, bool $gte, ?string $lt, ?string $eq = null, int $excludeBill = 0): float
    {
        [$dedSql, $dp] = Custody::deductionSql('custody', 'car_no');
        [$w, $p] = self::whereDate('bill_date', $gt, $gte, $lt, $eq);
        // is_legacy=0: البوالص المُرحَّلة من الأرشيف (v26) لا تدخل رصيد العهدة إطلاقًا
        $sql = "SELECT COALESCE(SUM($dedSql),0) FROM bawalis WHERE is_legacy = 0 AND custody > 0 AND $w";
        $params = array_merge($dp, $p);
        if ($excludeBill > 0) { $sql .= " AND id <> ?"; $params[] = $excludeBill; }
        return round((float) Database::scalar($sql, $params), 2);
    }

    /**
     * يجمع مصادر حركة الخزنة الستة في استعلام واحد. هذا هو المسار السريع للأرصدة
     * الحية؛ الدوال المفردة تبقى لعمليات الإقفال والتدقيق التي تحتاج كل رقم وحده.
     *
     * @return array{topups:float,expenses:float,driver:float}
     */
    private static function movementTotals(?string $gt, bool $gte, ?string $lt, ?string $eq = null, int $excludeBill = 0): array
    {
        $parts = []; $params = [];
        $append = static function (string $kind, string $sql, array $sqlParams) use (&$parts, &$params): void {
            $parts[] = "SELECT '$kind' kind, COALESCE(SUM(amount),0) total FROM ($sql) source_rows";
            array_push($params, ...$sqlParams);
        };

        [$w, $p] = self::whereDate('cust_date', $gt, $gte, $lt, $eq);
        $append('topups', "SELECT amount FROM manual_custody WHERE $w", $p);

        $expenseParts = []; $expenseParams = [];
        [$w, $p] = self::whereDate('s.entry_date', $gt, $gte, $lt, $eq);
        $expenseParts[] = "SELECT s.amount FROM permit_stages s WHERE " . Permit::expenseSql('s') . " AND s.is_visa=0 AND $w";
        array_push($expenseParams, ...$p);
        [$w, $p] = self::whereDate('exp_date', $gt, $gte, $lt, $eq);
        $expenseParts[] = "SELECT amount FROM manual_expenses WHERE is_visa=0 AND $w";
        array_push($expenseParams, ...$p);
        [$w, $p] = self::whereDate('entry_date', $gt, $gte, $lt, $eq);
        $expenseParts[] = "SELECT amount FROM rep_entries WHERE kind IN (" . Rep::cashKindsList() . ") AND is_visa=0 AND $w";
        array_push($expenseParams, ...$p);
        if (RepMiscExpense::available()) {
            [$w, $p] = self::whereDate('exp_date', $gt, $gte, $lt, $eq);
            // v45: الكاش بس — المدفوع بالفيزا خرج من الكارت مش من الخزنة.
            $expenseParts[] = "SELECT amount FROM rep_misc_expenses WHERE "
                            . RepMiscExpense::cashOnlySql() . " AND $w";
            array_push($expenseParams, ...$p);
        }
        $append('expenses', implode(' UNION ALL ', $expenseParts), $expenseParams);

        [$dedSql, $dedParams] = Custody::deductionSql('custody', 'car_no');
        [$w, $p] = self::whereDate('bill_date', $gt, $gte, $lt, $eq);
        $driverSql = "SELECT $dedSql amount FROM bawalis WHERE is_legacy=0 AND custody>0 AND $w";
        $driverParams = array_merge($dedParams, $p);
        if ($excludeBill > 0) { $driverSql .= ' AND id <> ?'; $driverParams[] = $excludeBill; }
        $append('driver', $driverSql, $driverParams);

        $totals = ['topups' => 0.0, 'expenses' => 0.0, 'driver' => 0.0];
        foreach (Database::all(implode(' UNION ALL ', $parts), $params) as $row) {
            $totals[$row['kind']] = round((float) $row['total'], 2);
        }
        return $totals;
    }

    // ───────────────────────────────────────────── نقطة الارتكاز

    /**
     * نقطة الارتكاز لحساب رصيد قبل $beforeDate (حصري)، أو آخر إقفال مطلقًا لو $beforeDate=null.
     * ترجع ['base'=>float, 'gt'=>?string, 'gte'=>bool]:
     *   نجمّع فوق base كل حركة بتاريخ (> gt أو >= gt حسب gte). gt=null → كل السجلات.
     */
    private static function anchor(?string $beforeDate): array
    {
        // نجرب قراءة الجدول مباشرةً؛ النجاح يثبت الجاهزية ويوفّر استعلام
        // information_schema إضافيًا في كل تحميل للداشبورد.
        try {
            $sql = "SELECT closing_balance, close_date FROM daily_closings";
            $params = [];
            if ($beforeDate !== null) { $sql .= " WHERE close_date < ?"; $params[] = $beforeDate; }
            $sql .= " ORDER BY close_date DESC LIMIT 1";
            $row = Database::row($sql, $params);
            self::$tableAvailable = true;
            if ($row) {
                return ['base' => round((float) $row['closing_balance'], 2), 'gt' => $row['close_date'], 'gte' => false];
            }
        } catch (\PDOException $error) {
            if ((string) $error->getCode() !== '42S02') { throw $error; }
            self::$tableAvailable = false;
        }
        // لا إقفال سابق — الرصيد الافتتاحي من الإعدادات (نفس منطق التقفيل القديم)
        $bal = (float) App::setting('opening_balance', '0');
        $d   = trim((string) App::setting('opening_balance_date', ''));
        if ($bal == 0.0) { return ['base' => 0.0, 'gt' => null, 'gte' => false]; }       // اجمع كل السجلات
        if ($d === '')   { return ['base' => round($bal, 2), 'gt' => null, 'gte' => false]; }
        // الرصيد الافتتاحي صالح اعتبارًا من بداية $d: اجمع الحركة بتاريخ >= $d فقط
        return ['base' => round($bal, 2), 'gt' => $d, 'gte' => true];
    }

    // ───────────────────────────────────────────── الأرصدة

    /** رصيد بداية يوم $date (قبل حركة اليوم نفسه). */
    public static function openingFor(string $date): float
    {
        $a = self::anchor($date);
        $movement = self::movementTotals($a['gt'], $a['gte'], $date);
        return round($a['base'] + $movement['topups'] - $movement['expenses'] - $movement['driver'], 2);
    }

    /**
     * الرصيد الحالي في العهدة = آخر إقفال + كل الحركة بتاريخ بعده (بلا حدّ علوي).
     * يخصم المصروفات، فهو الرصيد النقدي الفعلي المتاح. يمكن استثناء بوليصة عند تعديلها.
     */
    public static function currentBalance(int $excludeBill = 0): float
    {
        $a = self::anchor(null);
        $movement = self::movementTotals($a['gt'], $a['gte'], null, null, $excludeBill);
        return round($a['base'] + $movement['topups'] - $movement['expenses'] - $movement['driver'], 2);
    }

    /**
     * تفكيك الرصيد الحالي (لعرض صفحة العهدة) انطلاقًا من نقطة الارتكاز:
     * ['anchor'=>رصيد آخر إقفال, 'anchorDate'=>?تاريخه, 'topups', 'expenses', 'driver', 'balance'].
     * anchorDate=null → لا إقفال بعد، الحساب من بداية كل الحركة.
     */
    public static function currentBreakdown(): array
    {
        $a = self::anchor(null);
        $movement = self::movementTotals($a['gt'], $a['gte'], null);
        return [
            'anchor'     => $a['base'],
            'anchorDate' => $a['gt'],   // آخر يوم متقفل (أو تاريخ الرصيد الافتتاحي)، null لو من البداية
            'topups'     => $movement['topups'],
            'expenses'   => $movement['expenses'],
            'driver'     => $movement['driver'],
            'balance'    => round($a['base'] + $movement['topups'] - $movement['expenses'] - $movement['driver'], 2),
        ];
    }

    // ───────────────────────────────────────────── حالة الإقفال

    /** آخر تاريخ متقفل (أعلى close_date)، أو null. */
    public static function lastClosedDate(): ?string
    {
        if (!self::available()) { return null; }
        $max = Database::scalar("SELECT MAX(close_date) FROM daily_closings");
        return ($max === null || $max === false) ? null : $max;
    }

    /** هل اليوم متقفل؟ (أي تاريخ ≤ آخر يوم متقفل يقع ضمن الفترة المقفلة) */
    public static function isLocked(string $date): bool
    {
        $max = self::lastClosedDate();
        return $max !== null && $date <= $max;
    }

    /** هل لهذا التاريخ صفّ إقفال بالضبط؟ */
    public static function isClosedExact(string $date): bool
    {
        if (!self::available()) { return false; }
        return (bool) Database::scalar("SELECT COUNT(*) FROM daily_closings WHERE close_date = ?", [$date]);
    }

    /** رسالة منع لو اليوم متقفل، أو null للسماح. تُستدعى قبل أي كتابة مؤرّخة. */
    public static function guard(?string $date): ?string
    {
        if ($date === null || $date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return null; }
        if (self::isLocked($date)) {
            if (Auth::freeEditMode()) {
                self::scheduleFreeRefresh($date);
                return null;
            }
            return 'اليوم (' . dfmt($date) . ') متقفل. افتح الإقفال أولاً من صفحة «التقفيل اليومي» قبل أي تعديل على بياناته.';
        }
        return null;
    }

    /**
     * الدخول الحر لا يفتح الإقفالات. بعد انتهاء الطلب يعيد بناء كل اللقطات المقفولة
     * من أقدم تاريخ لُمِس، حتى تظل الأرصدة المرحّلة صحيحة بعد تعديل بيانات قديمة.
     */
    private static function scheduleFreeRefresh(string $date): void
    {
        if (self::$freeRefreshFrom === null || $date < self::$freeRefreshFrom) {
            self::$freeRefreshFrom = $date;
        }
        if (self::$freeRefreshRegistered) { return; }
        self::$freeRefreshRegistered = true;
        register_shutdown_function(function (): void {
            $from = self::$freeRefreshFrom;
            if ($from === null) { return; }
            try { self::refreshLockedChainFrom($from); }
            catch (\Throwable $e) {
                error_log('free-edit closing refresh failed: ' . $e->getMessage());
                // تظهر في أول صفحة تالية؛ لا يجوز إخفاء فشل تحديث رصيد مرحّل.
                flash('error', 'تم حفظ التعديل، لكن تعذّر إعادة حساب الإقفالات تلقائيًا. راجع صفحة التقفيل قبل مواصلة العمل.');
            }
        });
    }

    /** إعادة حساب الإقفالات المتأثرة بالترتيب مع الحفاظ على صاحب/وقت الإقفال الأصلي. */
    public static function refreshLockedChainFrom(string $fromDate): int
    {
        if (!self::available()) { return 0; }
        $dates = array_column(Database::all(
            'SELECT close_date FROM daily_closings WHERE close_date >= ? ORDER BY close_date', [$fromDate]
        ), 'close_date');
        if (!$dates) { return 0; }

        $pdo = Database::conn();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) { $pdo->beginTransaction(); }
        try {
            foreach ($dates as $date) {
                $opening = self::openingFor($date);
                $topups  = self::sumTopups(null, false, null, $date);
                $expense = self::sumExpenses(null, false, null, $date);
                $driver  = self::sumDriverCustody(null, false, null, $date);
                $closing = round($opening + $topups - $expense - $driver, 2);
                Database::exec(
                    'UPDATE daily_closings SET opening=?, topups=?, expenses=?, driver_custody=?, closing_balance=? WHERE close_date=?',
                    [$opening, $topups, $expense, $driver, $closing, $date]
                );
            }
            if ($ownTransaction) { $pdo->commit(); }
            return count($dates);
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /** هل توجد أي حركة (topup/مصروف/عهدة) بتاريخ بعد آخر إقفال وقبل $beforeDate؟ (كشف الفجوات) */
    private static function hasMovementBetween(?string $afterClosed, string $beforeDate): bool
    {
        $expSql = Permit::expenseSql('s');
        [$w, $p] = self::whereDate('cust_date', $afterClosed, false, $beforeDate, null);
        if (Database::scalar("SELECT COUNT(*) FROM manual_custody WHERE $w", $p)) { return true; }
        [$w, $p] = self::whereDate('s.entry_date', $afterClosed, false, $beforeDate, null);
        if (Database::scalar("SELECT COUNT(*) FROM permit_stages s WHERE $expSql AND s.is_visa=0 AND $w", $p)) { return true; }
        [$w, $p] = self::whereDate('exp_date', $afterClosed, false, $beforeDate, null);
        if (Database::scalar("SELECT COUNT(*) FROM manual_expenses WHERE is_visa=0 AND $w", $p)) { return true; }
        [$w, $p] = self::whereDate('bill_date', $afterClosed, false, $beforeDate, null);
        if (Database::scalar("SELECT COUNT(*) FROM bawalis WHERE is_legacy=0 AND custody>0 AND $w", $p)) { return true; }
        if (self::tableExists('rep_entries')) {
            $cash = Rep::cashKindsList();
            [$w, $p] = self::whereDate('entry_date', $afterClosed, false, $beforeDate, null);
            if (Database::scalar("SELECT COUNT(*) FROM rep_entries WHERE kind IN ($cash) AND is_visa=0 AND $w", $p)) { return true; }
        }
        // نثريات المناديب (v39) بقت حركة خزنة من v42 — الكاش بس (v45)
        if (self::tableExists('rep_misc_expenses')) {
            [$w, $p] = self::whereDate('exp_date', $afterClosed, false, $beforeDate, null);
            $cash = RepMiscExpense::cashOnlySql();
            if (Database::scalar("SELECT COUNT(*) FROM rep_misc_expenses WHERE $cash AND $w", $p)) { return true; }
        }
        return false;
    }

    // ───────────────────────────────────────────── عرض يوم (للتقفيل)

    /**
     * بيانات يوم للتقفيل. إن كان متقفلًا (له صفّ) يُعرض من القيم المجمّدة المخزَّنة،
     * وإلا يُحسب حيًّا. المفاتيح: is_locked, stored, opening, topups, expenses, driver, closing, closed_by, closed_at.
     */
    public static function dayView(string $date): array
    {
        if (self::available()) {
            $row = Database::row("SELECT * FROM daily_closings WHERE close_date = ?", [$date]);
            if ($row) {
                return [
                    'is_locked' => true,
                    'stored'    => true,
                    'opening'   => round((float) $row['opening'], 2),
                    'topups'    => round((float) $row['topups'], 2),
                    'expenses'  => round((float) $row['expenses'], 2),
                    'driver'    => round((float) $row['driver_custody'], 2),
                    'closing'   => round((float) $row['closing_balance'], 2),
                    'closed_by' => $row['closed_by'],
                    'closed_at' => $row['closed_at'],
                ];
            }
        }
        $opening = self::openingFor($date);
        $t = self::sumTopups(null, false, null, $date);
        $e = self::sumExpenses(null, false, null, $date);
        $d = self::sumDriverCustody(null, false, null, $date);
        return [
            'is_locked' => self::isLocked($date),
            'stored'    => false,
            'opening'   => $opening,
            'topups'    => $t,
            'expenses'  => $e,
            'driver'    => $d,
            'closing'   => round($opening + $t - $e - $d, 2),
            'closed_by' => null,
            'closed_at' => null,
        ];
    }

    // ───────────────────────────────────────────── إقفال / فتح

    /**
     * إقفال يوم. الشروط: غير متقفل، ولا توجد أيام أقدم غير متقفلة تحمل حركة (بلا فجوات).
     * يخزّن القيم مجمّدة. يعيد ['ok'=>bool, 'msg'=>string].
     */
    public static function closeDay(string $date, ?string $user): array
    {
        if (!self::available()) {
            return ['ok' => false, 'msg' => 'جدول الإقفالات غير موجود. شغّل php bin/migrate_v18.php أولاً.'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['ok' => false, 'msg' => 'تاريخ غير صحيح.'];
        }
        if (self::isClosedExact($date)) {
            return ['ok' => false, 'msg' => 'اليوم متقفل بالفعل.'];
        }
        $lastClosed = self::lastClosedDate();
        if ($lastClosed !== null && $date <= $lastClosed) {
            return ['ok' => false, 'msg' => 'هذا اليوم يقع ضمن فترة متقفلة بالفعل.'];
        }
        if (self::hasMovementBetween($lastClosed, $date)) {
            return ['ok' => false, 'msg' => 'توجد أيام أقدم غير متقفلة تحمل حركة. اقفل الأيام بالترتيب من الأقدم أولاً.'];
        }
        $opening = self::openingFor($date);
        $t = self::sumTopups(null, false, null, $date);
        $e = self::sumExpenses(null, false, null, $date);
        $d = self::sumDriverCustody(null, false, null, $date);
        $closing = round($opening + $t - $e - $d, 2);
        Database::exec(
            "INSERT INTO daily_closings
                (close_date, opening, topups, expenses, driver_custody, closing_balance, is_locked, closed_by, closed_at)
             VALUES (?,?,?,?,?,?,1,?,NOW())",
            [$date, $opening, $t, $e, $d, $closing, $user]
        );
        return ['ok' => true, 'msg' => 'تم إقفال يوم ' . dfmt($date) . ' على رصيد ' . number_format($closing, 2) . ' ج.'];
    }

    /**
     * فتح إقفال يوم. تسلسلي: لا يُفتح إلا إن لم يوجد يوم أحدث متقفل (الفتح من الأحدث للأقدم).
     * يعيد ['ok'=>bool, 'msg'=>string].
     */
    public static function reopenDay(string $date): array
    {
        if (!self::isClosedExact($date)) {
            return ['ok' => false, 'msg' => 'اليوم غير متقفل.'];
        }
        if (Database::scalar("SELECT COUNT(*) FROM daily_closings WHERE close_date > ?", [$date])) {
            return ['ok' => false, 'msg' => 'توجد أيام أحدث متقفلة. افتح الأحدث أولاً (الفتح من الأحدث للأقدم).'];
        }
        Database::exec("DELETE FROM daily_closings WHERE close_date = ?", [$date]);
        return ['ok' => true, 'msg' => 'تم فتح إقفال يوم ' . dfmt($date) . '. يمكنك الآن التعديل على بياناته.'];
    }

    /** قائمة كل الإقفالات (الأحدث أولًا) — للعرض/التدقيق. */
    public static function all(): array
    {
        if (!self::available()) { return []; }
        return Database::all("SELECT * FROM daily_closings ORDER BY close_date DESC");
    }

    /** أول تاريخ يحمل أي حركة في النظام (لبدء الترحيل بأثر رجعي). */
    public static function earliestMovementDate(): ?string
    {
        $dates = array_filter([
            Database::scalar("SELECT MIN(cust_date) FROM manual_custody"),
            Database::scalar("SELECT MIN(s.entry_date) FROM permit_stages s"),
            Database::scalar("SELECT MIN(exp_date) FROM manual_expenses"),
            Database::scalar("SELECT MIN(bill_date) FROM bawalis WHERE is_legacy = 0 AND custody > 0"),
            self::tableExists('rep_entries')
                ? Database::scalar("SELECT MIN(entry_date) FROM rep_entries WHERE kind IN (" . Rep::cashKindsList() . ")")
                : null,
            self::tableExists('rep_misc_expenses')
                ? Database::scalar("SELECT MIN(exp_date) FROM rep_misc_expenses WHERE "
                    . RepMiscExpense::cashOnlySql())
                : null,
        ], fn($d) => $d !== null && $d !== false && $d !== '');
        if (!$dates) { return null; }
        sort($dates);
        return $dates[0];
    }
}
