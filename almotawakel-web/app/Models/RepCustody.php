<?php
namespace App\Models;

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
        if ($day === null || $day === '') { return ''; }
        $args[] = $day;
        return " AND $col = ?";
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
             WHERE rep_id = ? GROUP BY dep_date", [$repId])), 'deposits');

        $add($pairs(Database::all(
            "SELECT entry_date d, SUM(amount) total FROM permit_stages
             WHERE paid_by_rep_id = ? AND " . Permit::cashOutSql('') . " GROUP BY entry_date", [$repId])), 'spent');
        $add(RepMiscExpense::totalsByDay($repId), 'spent');
        $add(RepDriverHandover::totalsByDay($repId), 'spent');
        $add(RepBillCustody::totalsByDay($repId), 'spent');   // v60 — عهدة بوليصة من محفظته

        $add($pairs(Database::all(
            "SELECT settle_date d, SUM(returned) total FROM rep_custody_settlements
             WHERE rep_id = ? GROUP BY settle_date", [$repId])), 'returned');

        $add(RepTransfer::inTotalsByDay($repId), 'transfer_in');
        $add(RepTransfer::outTotalsByDay($repId), 'transfer_out');

        // المستنّي اعتماده كاش: خرج من إيد المندوب فعلاً في اليوم ده وإن كان
        // لسه ما اتحسبش في `spent` (بيتحسب وقت الاعتماد). عشان كده الشاشات
        // بتعرض `pocket` (= balance − pending) كـ«الفاضل معاه»، و`balance`
        // بيفضل الرصيد المحاسبي بعد المعتمد بس.
        $add($pairs(Database::all(
            "SELECT entry_date d, SUM(amount) total FROM rep_submissions
             WHERE rep_id = ? AND status = 'pending' AND is_visa = 0
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
                       WHERE dep_date = ? GROUP BY rep_id"), 'deposits');
        $merge($byRep("SELECT paid_by_rep_id rid, SUM(amount) total FROM permit_stages
                       WHERE paid_by_rep_id IS NOT NULL AND " . Permit::cashOutSql('') . " AND entry_date = ?
                       GROUP BY paid_by_rep_id"), 'spent');
        $merge(RepMiscExpense::totalsByRep($day), 'spent');
        $merge(RepDriverHandover::totalsByRep($day), 'spent');
        $merge(RepBillCustody::totalsByRep($day), 'spent');   // v60
        $merge($byRep("SELECT rep_id rid, SUM(returned) total FROM rep_custody_settlements
                       WHERE settle_date = ? GROUP BY rep_id"), 'returned');
        $merge(RepTransfer::inTotalsByRep($day), 'transfer_in');
        $merge(RepTransfer::outTotalsByRep($day), 'transfer_out');
        $merge($byRep("SELECT rep_id rid, SUM(amount) total FROM rep_submissions
                       WHERE status = 'pending' AND is_visa = 0 AND entry_date = ?
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
             WHERE d.dep_date = ?
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
                        FROM rep_custody_deposits GROUP BY rep_id) d ON d.rep_id = r.id
             LEFT JOIN (SELECT paid_by_rep_id rid, SUM(amount) total
                        FROM permit_stages WHERE paid_by_rep_id IS NOT NULL AND " . Permit::cashOutSql('') . "
                        GROUP BY paid_by_rep_id) s ON s.rid = r.id
             LEFT JOIN (SELECT rep_id, SUM(returned) total
                        FROM rep_custody_settlements GROUP BY rep_id) t ON t.rep_id = r.id
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
        $dep = (float) Database::scalar("SELECT COALESCE(SUM(amount),0) FROM rep_custody_deposits");
        $spent = (float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM permit_stages
             WHERE paid_by_rep_id IS NOT NULL AND " . Permit::cashOutSql(''));
        $ret = (float) Database::scalar("SELECT COALESCE(SUM(returned),0) FROM rep_custody_settlements");
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

    /** تنزيل عهدة جديد. **لا يمسّ الخزنة** (زي شحن كارت الفيزا). */
    public static function createDeposit(int $repId, float $amount, ?string $date, string $note = ''): int
    {
        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            self::lockRep($repId);
            Database::exec(
                "INSERT INTO rep_custody_deposits (rep_id, dep_date, amount, note, created_by)
                 VALUES (?,?,?,?,?)",
                [$repId, $date ?: null, round(abs($amount), 2), $note !== '' ? $note : null, Auth::user()]
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
             WHERE s.paid_by_rep_id = ?", [$repId]);

        foreach (RepMiscExpense::forRep($repId) as $m) {
            $rows[] = [
                'id' => (int) $m['id'], 'src' => 'misc', 'd' => $m['exp_date'],
                'note' => $m['note'], 'qty' => (int) $m['qty'], 'amount' => $m['amount'],
                'ref_no' => $m['ref_no'], 'stage_key' => 'custom', 'direction' => 'expense',
                'category' => $m['category'],
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

        // v60 — عهدة بوليصة أضافها المندوب ودفعها من محفظته. المبلغ هنا
        // **العهدة كاملة** مش «العهدة − 100»: الـ100 قاعدة خزنة المكتب،
        // والمندوب سلّم السائق المبلغ كامل من جيبه.
        foreach (RepBillCustody::forRep($repId) as $b) {
            $rows[] = [
                'id' => (int) $b['id'], 'src' => 'bill', 'd' => $b['bill_date'],
                'note' => $b['bill_number'] ? ('بوليصة ' . $b['bill_number']) : null,
                'qty' => 1, 'amount' => $b['custody'],
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
                'id' => (int) $t['id'], 'src' => 'transfer', 'd' => $t['transfer_date'],
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
     * تحويل لزميل. و`visa` بند مدفوع بكارت المكتب — **بيتعرض للعلم وما بيتخصمش
     * من عهدة المندوب** (قاعدة v45)، عشان كده `cash` منفصل عن `total`.
     *
     * @return array{receipts:array,expenses:array,misc:array,handover:array,
     *               transfer:array,visa:array,cash:float,total:float,rows:array}
     */
    public static function dayActivity(int $repId, string $day): array
    {
        $bucket = static fn (): array => ['cnt' => 0, 'sum' => 0.0];
        $out = [
            'receipts' => $bucket(), 'expenses' => $bucket(), 'misc' => $bucket(),
            'handover' => $bucket(), 'bill' => $bucket(), 'transfer' => $bucket(),
            'visa' => $bucket(),
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
            if (!empty($r['is_visa'])) {
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
            "SELECT * FROM rep_custody_settlements WHERE rep_id = ?
             ORDER BY settle_date DESC, id DESC", [$repId]);
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
             WHERE rep_id = ? AND status = 'pending' GROUP BY entry_date", [$repId]) as $r) {
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
        $miscJoin = $miscReady
            ? "LEFT JOIN (SELECT rep_id, SUM(amount) total FROM rep_misc_expenses WHERE "
                . RepMiscExpense::cashOnlySql() . " GROUP BY rep_id) x ON x.rep_id=r.id"
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
                    (SELECT COUNT(*) FROM rep_submissions WHERE entry_date=?) _sent_cnt,
                    (SELECT COALESCE(SUM(amount),0) FROM rep_submissions WHERE entry_date=?) _sent_sum,
                    (SELECT COUNT(*) FROM permit_stages WHERE paid_by_rep_id IS NOT NULL AND " . Permit::cashOutSql('') . " AND entry_date=?) _paid_cnt,
                    (SELECT COALESCE(SUM(amount),0) FROM permit_stages WHERE paid_by_rep_id IS NOT NULL AND " . Permit::cashOutSql('') . " AND entry_date=?) _paid_sum
                 FROM reps r
                 LEFT JOIN (SELECT rep_id, SUM(amount) total, MAX(dep_date) last_date
                            FROM rep_custody_deposits GROUP BY rep_id) d ON d.rep_id=r.id
                 LEFT JOIN (SELECT paid_by_rep_id rep_id, SUM(amount) total, MAX(entry_date) last_spend
                            FROM permit_stages WHERE paid_by_rep_id IS NOT NULL AND " . Permit::cashOutSql('') . " GROUP BY paid_by_rep_id) s ON s.rep_id=r.id
                 LEFT JOIN (SELECT rep_id, SUM(returned) total
                            FROM rep_custody_settlements GROUP BY rep_id) t ON t.rep_id=r.id
                 LEFT JOIN (SELECT rep_id, COUNT(*) cnt, $pendingSum total
                            FROM rep_submissions WHERE status='pending' GROUP BY rep_id) p ON p.rep_id=r.id
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
                'LEFT JOIN (SELECT rep_id, SUM(amount) total FROM rep_driver_handovers GROUP BY rep_id) h ON h.rep_id=r.id'
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
             WHERE status = 'pending' AND entry_date = ? GROUP BY rep_id", [$day]) as $r) {
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
             WHERE s.entry_date = ?
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

    /** اسم المندوب (للعرض) أو '' لو مش موجود. */
    public static function repName(?int $repId): string
    {
        if (!$repId) { return ''; }
        return (string) (Database::scalar("SELECT name FROM reps WHERE id = ?", [$repId]) ?: '');
    }
}
