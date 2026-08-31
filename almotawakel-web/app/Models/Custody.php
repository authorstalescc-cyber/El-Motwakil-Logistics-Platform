<?php
namespace App\Models;

use App\Core\App;
use App\Core\Database;
use App\Core\Settings;

/**
 * رصيد عهدة المكتب (محفظة العهدة) — صفحة مستقلة بسجل تراكمي.
 *
 * القواعد (كما اتفق عليها صاحب الشركة):
 *  • الرصيد يزيد بـ«إضافة عهدة يدوية» (توب-أب في جدول manual_custody).
 *  • الرصيد يقل بعهد السائقين فقط (bawalis.custody) — لا تُخصم منه مصروفات المكتب.
 *  • يُطرح مبلغ ثابت (افتراضي 100ج) من عهدة كل بوليصة قبل خصمها من الرصيد:
 *      خصم البوليصة = عهدة السائق − 100 (لا يقل عن صفر). الـ100 تبقى في الرصيد.
 *  • استثناء: سيارات مُعيَّنة (setting: custody_deduction_exempt_cars، افتراضيًا «س ع م 8737»)
 *    تُخصم عهدتها كاملة بلا طرح الـ100. المطابقة على bawalis.car_no بعد إزالة المسافات.
 *  • قاعدة: عهدة أي بوليصة لا يمكن أن تتجاوز رصيد عهدة المكتب المتاح.
 *
 * الرصيد = Σ(الإضافات اليدوية) − Σ(عهدة كل بوليصة − 100 [أو كاملة للسيارات المستثناة]).
 */
class Custody
{
    /**
     * المبلغ المطروح من عهدة كل بوليصة (setting: custody_bill_deduction، افتراضي 100).
     *
     * ⚠️⚠️ **الرقم ده بيتغيّر «من تاريخ» لا بأثر رجعي** (v74). مرّر `$onDate`
     *    (تاريخ البوليصة) عشان تاخد القيمة اللي كانت سارية **يومها** — من غيره
     *    بتاخد السارية النهارده، وده بيدّي حسابًا غلطًا لأي بوليصة قديمة بعد
     *    أول تغيير مؤرَّخ.
     *
     * ⚠️ `$onDate = null` بترجّع السارية النهارده — نفس السلوك القديم بالحرف،
     *    عشان أي مستدعي لسه ما اتحوّلش يفضل شغّال.
     */
    public static function deductionRate(?string $onDate = null): float
    {
        return (float) Settings::valueOn('custody_bill_deduction', $onDate);
    }

    /** توحيد رقم السيارة للمقارنة: إزالة كل المسافات (يطابق REPLACE(car_no,' ','') في SQL). */
    public static function normCar(?string $carNo): string
    {
        return preg_replace('/\s+/u', '', (string) $carNo);
    }

    /**
     * أرقام السيارات المستثناة من قاعدة طرح الـ100 (تُخصم عهدتها كاملة).
     * setting: custody_deduction_exempt_cars — قائمة مفصولة بفواصل/أسطر، افتراضيًا «س ع م 8737».
     * تُعاد كخريطة [رقم مُوحَّد => true] لسرعة الفحص.
     */
    public static function exemptCars(): array
    {
        $raw = (string) App::setting('custody_deduction_exempt_cars', 'س ع م 8737');
        $out = [];
        foreach (preg_split('/[,\n\r]+/u', $raw) as $c) {
            $norm = self::normCar($c);
            if ($norm !== '') { $out[$norm] = true; }
        }
        return $out;
    }

    /** هل هذه السيارة مستثناة من طرح الـ100؟ */
    public static function isExempt(?string $carNo): bool
    {
        $norm = self::normCar($carNo);
        return $norm !== '' && isset(self::exemptCars()[$norm]);
    }

    /**
     * تعبير SQL يحسب المخصوم من الرصيد لكل بوليصة داخل SUM(...):
     *  • سيارة مستثناة → تُخصم العهدة كاملة.
     *  • غير ذلك → GREATEST(custody − rate, 0).
     * يُعيد [sql, params]؛ تُدمج params بالترتيب في مكان التعبير داخل الاستعلام.
     */
    public static function deductionSql(
        string $custodyCol = 'custody',
        string $carCol = 'car_no',
        ?string $dateCol = null
    ): array {
        /* ⚠️⚠️ **`$dateCol` هو اللي بيخلّي الماضي متجمّدًا** (v74): من غيره كل
           صف بياخد القيمة السارية النهارده، فأول تغيير مؤرَّخ بيعيد حساب كل
           البوالص القديمة بالسكوت. مرّر عمود تاريخ البوليصة (`bill_date`).
           ⚠️ ولو السلسلة فيها صف واحد بس، `datedSql` بترجّع `?` عادي — يعني
              نفس شكل الاستعلام القديم بالحرف. */
        [$rateSql, $rateArgs] = $dateCol !== null
            ? Settings::datedSql('custody_bill_deduction', $dateCol)
            : ['?', [self::deductionRate()]];

        $exempt = array_keys(self::exemptCars());
        if (!$exempt) {
            return ["GREATEST($custodyCol - $rateSql, 0)", $rateArgs];
        }
        $ph  = implode(',', array_fill(0, count($exempt), '?'));
        $sql = "CASE WHEN REPLACE($carCol, ' ', '') IN ($ph) THEN $custodyCol"
             . " ELSE GREATEST($custodyCol - $rateSql, 0) END";
        return [$sql, array_merge($exempt, $rateArgs)];
    }

    /**
     * خصم بوليصة واحدة من الرصيد = العهدة − المطروح (لا يقل عن صفر).
     * السيارة المستثناة تُخصم عهدتها كاملة (المطروح = 0).
     *
     * ⚠️ **مرّر `$onDate` = تاريخ البوليصة** (v74) عشان تاخد القيمة اللي كانت
     *    سارية يومها. `null` = السارية النهارده (السلوك القديم بالحرف).
     */
    public static function billDeduction(float $custody, ?string $carNo = null, ?string $onDate = null): float
    {
        if ($custody <= 0) { return 0.0; }
        $rate = self::isExempt($carNo) ? 0.0 : self::deductionRate($onDate);
        return round(max($custody - $rate, 0), 2);
    }

    /**
     * **المحتجز من البوليصة** = العهدة − المخصوم من الرصيد (v87).
     *
     * ده الجزء اللي **ما خرجش للسائق** وفضل كاش في درج العهدة — وهو نفسه
     * مصدر كومسيون المناديب (قرار المالك 2026-08-11: «هو فرق الـ١٠٠ اللي في
     * البوليصة»). **المصدر الوحيد** للسؤال ده؛ ممنوع أي ملف يكتب
     * `min(custody, 100)` بإيده.
     *
     * ⚠️ **مش دايمًا = المعدّل**:
     *   • عهدة ٦٠ ومعدّل ١٠٠ ← المحتجز ٦٠ (مينفعش نحتجز أكتر من الموجود).
     *   • عهدة صفر ← صفر.
     *   • **سيارة معفاة** ← صفر: عهدتها بتتخصم كاملة فمحصلش احتجاز أصلاً،
     *     يعني بوليصتها **ما جمّعتش كومسيون** رغم إن المندوب اشتغل عليها.
     *
     * ⚠️ مرّر `$onDate` = تاريخ البوليصة عشان تاخد المعدّل اللي كان ساريًا يومها.
     */
    public static function retained(float $custody, ?string $carNo = null, ?string $onDate = null): float
    {
        if ($custody <= 0) { return 0.0; }
        return round($custody - self::billDeduction($custody, $carNo, $onDate), 2);
    }

    // ─────────────────────────────────────────────── الرصيد والإجماليات

    /** إجمالي الإضافات اليدوية (توب-أب). */
    public static function topupsTotal(): float
    {
        return round((float) Database::scalar("SELECT COALESCE(SUM(amount),0) FROM manual_custody"), 2);
    }

    /**
     * إجمالي عهد السائقين المخصومة من الرصيد (بعد طرح الـ100 لكل بوليصة).
     * يمكن استثناء بوليصة (عند تعديلها) لحساب المتاح لها.
     */
    public static function driverCustodyOut(int $excludeBillId = 0): float
    {
        [$dedSql, $params] = self::deductionSql('custody', 'car_no');
        // is_legacy=0: البوالص المُرحَّلة من الأرشيف (v26) خارج رصيد العهدة تمامًا
        // v60: عهدة دفعها مندوب من محفظته مش من درج المكتب — خارج الرصيد ده.
        $sql = "SELECT COALESCE(SUM($dedSql),0)
                FROM bawalis WHERE is_legacy = 0 AND custody > 0"
             . RepBillCustody::officeExcludeSql()
             . ($excludeBillId > 0 ? " AND id <> ?" : "");
        if ($excludeBillId > 0) { $params[] = $excludeBillId; }
        return round((float) Database::scalar($sql, $params), 2);
    }

    /**
     * الرصيد الحالي في العهدة.
     * يُحسب عبر نظام الإقفال اليومي (App\Models\Closing): آخر إقفال + كل الحركة بعده،
     * وهو **يخصم المصروفات** (بنود + يدوية) — فيطابق الرصيد المرحّل في التقفيل اليومي،
     * بعكس الحساب القديم (topups − driverOut) الذي كان يتجاهل المصروفات.
     */
    public static function balance(): float
    {
        return Closing::currentBalance();
    }

    /**
     * الرصيد المتاح لبوليصة معيّنة (يستثني خصمها الحالي حتى لا يُحتسب مرّتين عند التعديل).
     * للبوليصة الجديدة مرّر 0. يعتمد نفس منطق الإقفال (يخصم المصروفات).
     */
    public static function availableForBill(int $excludeBillId = 0): float
    {
        return Closing::currentBalance($excludeBillId);
    }

    /**
     * قاعدة سقف العهدة — المصدر الوحيد للفحص (تناديها BawalisController::validate
     * و PermitController::updateBill؛ ممنوع تكتب الشرط بإيدك في مكان تالت).
     *
     * ⚠️ القاعدة على **الفلوس اللي هتطلع من الدرج دلوقتي**، مش على عهدة البوليصة كلها:
     *  • بوليصة جديدة   → الخصم كله جديد، فيتقارن بالرصيد المتاح (السلوك القديم).
     *  • تعديل بوليصة   → يتقارن **الفرق** بين الخصم الجديد والقديم فقط. يعني تعديل
     *    التاريخ أو السائق أو أي حقل بلا زيادة في العهدة **مش بيتفحص خالص**.
     *
     * ليه اتغيّرت: القديمة كانت بتقارن العهدة كلها بالرصيد الحالي في كل تعديل، وده
     * كان بيمنع تعديل أي بوليصة قديمة **حتى لو ما لمستش المبلغ**. والسبب إن
     * Closing::currentBalance بيبني على آخر إقفال، وخصم البوليصة اللي تاريخها قبل
     * آخر إقفال بيبقى **متجمّد جوّه اللقطة**، فـ$excludeBill مش بيقدر يرجّعه للرصيد
     * → الرصيد المتاح بيطلع أقل من عهدة البوليصة نفسها والحفظ يترفض بلا سبب حقيقي.
     * المقارنة بالفرق سليمة مهما كان تاريخ البوليصة، لأن الزيادة بس هي اللي بتطلع
     * كاش النهاردة.
     *
     * البوالص المُرحَّلة من الأرشيف (is_legacy=1) خارج كل الأرصدة → بلا فحص.
     *
     * @return string|null رسالة الخطأ، أو null لو مسموح.
     */
    public static function capError(float $newCustody, ?string $newCarNo, ?int $billId): ?string
    {
        $old = ($billId !== null && $billId > 0)
            ? Database::row('SELECT is_legacy, custody, car_no FROM bawalis WHERE id = ?', [$billId])
            : null;

        if ($old && (int) $old['is_legacy'] === 1) { return null; }

        // ⚠️ v60: عهدة البوليصة دي مدفوعة من محفظة مندوب مش من درج المكتب، فحدّها
        //    الحقيقي هو عهدة المندوب — وهو **تحذير مش منع** (قرار المالك في v49:
        //    الميدان بيدفع فيه المندوب من جيبه والرصيد السالب مسموح). فحصها بسقف
        //    الخزنة كان هيمنع المكتب من تسجيل صرف حصل فعلاً في الميدان.
        // ⚠️ **ملحوظة بعد تصحيح 2026-07-31:** في النموذج المجمّع («الخزنة = كل
        //    كاش الشركة») البوليصة دي **بقت تتخصم من رقم الخزنة** فعلاً
        //    (`officeExcludeSql`). الإعفاء من السقف هنا **مقصود ومتساب زي ما هو**
        //    عن قصد: السقف حارس للدرج، والمندوب بيصرف من محفظته هو.
        if ($old && RepBillCustody::billIsRepPaid($billId)) { return null; }

        $newOut = self::billDeduction(max($newCustody, 0), $newCarNo);

        if ($old) {
            $oldOut = self::billDeduction((float) $old['custody'], $old['car_no'] ?? null);
            $extra  = round($newOut - $oldOut, 2);
            if ($extra <= 0.005) { return null; }   // مفيش فلوس زيادة هتطلع → تعديل حر
            $available = Closing::currentBalance();
            if ($extra > $available + 0.005) {
                return 'الزيادة في عهدة السائق (' . number_format($extra, 0) . ') تتجاوز رصيد خزنة '
                     . 'المكتب المتاح (' . number_format($available, 0) . '). أضِف عهدة للرصيد أولاً '
                     . 'من صفحة «خزنة المكتب»، أو قلّل المبلغ. (باقي بيانات البوليصة تتعدّل بحرية.)';
            }
            return null;
        }

        if ($newOut <= 0) { return null; }
        $available = self::availableForBill(0);
        if ($newOut > $available + 0.005) {
            return 'عهدة السائق (' . number_format($newCustody, 0) . ') تتجاوز رصيد خزنة المكتب المتاح ('
                 . number_format($available, 0) . '). أضِف عهدة للرصيد أولاً من صفحة «خزنة المكتب».';
        }
        return null;
    }

    // ─────────────────────────────────────────────── حركة الخزنة في فترة

    /**
     * ⚠️⚠️ **المصدر الوحيد لصفوف حركة درج المكتب — وممنوع يتحسب هنا أي رقم من الأول.**
     *
     * ═══ ليه الدالة القديمة (`ledger()`) اتشالت ═══
     * كانت بتحسب `Σ(الإضافات) − Σ(عهدة البوليصة − ١٠٠)` بإيدها، بينما الرصيد
     * المعروض فوق بييجي من `Closing::currentBalance()` = `آخر إقفال + إضافات −
     * **مصروفات** − عهد سائقين`. يعني السجل كان ناقصه ٣ حاجات:
     *   ① رصيد نقطة الارتكاز (كان بيبدأ من صفر بدل رصيد آخر إقفال)
     *   ② **المصروفات كلها** (أكبر بند صرف في الخزنة أصلاً — ما كانش ليه ولا سطر)
     *   ③ `rep_custody_deposits` (بتتحسب إضافة أو مصروف حسب النموذج الفعّال)
     * النتيجة كانت **رقمين مختلفين لنفس السؤال على نفس الشاشة** (مقيس على
     * قاعدة التطوير 2026-08-31: الكارت ٢٤٥,٠٥٠ والسجل ٥٧٢,٧٩٠ — فرق ٣٢٧,٧٤٠).
     *
     * ═══ ليه `DailyReview::officeCashItems` بالذات ═══
     * لأن `verify_v108` **بيحرس بالفعل** إن `Σ(signed)` عليها =
     * `Closing::rangeMovement()['net']` بالمليم. فالسجل المبني عليها **مستحيل**
     * يخالف الرصيد — الفجوة مقفولة بالبناء مش بالمراجعة.
     *
     * ⚠️ **الرصيد الجاري بيبدأ من `Closing::openingFor($from)`** مش من صفر —
     *    ده اللي بيخلّي آخر سطر = `Closing::closingFor($to)`.
     *
     * ⚠️ **`drift`**: الفرق بين (رصيد آخر المدة) و(رصيد أولها + صافي الحركة).
     *    المفروض صفر؛ أي رقم تاني معناه **لقطة إقفال اتقادمت** بعد تعديل على
     *    بيانات يوم متقفل. الشاشة بتقوله صراحةً بدل ما تخبّيه (نفس مفهوم
     *    `SafeInventory::statement`).
     *
     * ⚠️ **السقف من `SafeInventory`** مش ثوابت جديدة — الصفوف بتتبني بنداء
     *    `DailyReview` **لكل يوم**، فالفترة الأطول من `MAX_ROW_DAYS` بترجّع
     *    الإجماليات كاملة و`capped=true`، والشاشة بتقول إن الصفوف مقطوعة
     *    (**ممنوع قطع صامت**).
     *
     * @return array{from:string,to:string,days:int,opening:float,closing:float,
     *               in:float,out:float,net:float,drift:float,parts:array<string,float>,
     *               rows:list<array<string,mixed>>,capped:bool,rows_total:int}
     */
    public static function movements(string $from, string $to): array
    {
        if ($from > $to) { [$from, $to] = [$to, $from]; }

        $opening = round(Closing::openingFor($from), 2);
        $closing = round(Closing::closingFor($to), 2);
        $move    = Closing::rangeMovement($from, $to);
        $net     = round((float) $move['net'], 2);

        $days   = (int) floor((strtotime($to) - strtotime($from)) / 86400) + 1;
        $capped = $days > SafeInventory::MAX_ROW_DAYS;

        $rows = [];
        if (!$capped) {
            for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                foreach (DailyReview::officeCashItems($d) as $it) {
                    $rows[] = [
                        'date'   => $d,
                        'group'  => (string) $it['group'],
                        'icon'   => self::groupIcon((string) $it['group']),
                        'title'  => (string) $it['title'],
                        'ref'    => trim((string) ($it['ref'] ?? '')) ?: null,
                        'party'  => ($it['party'] ?? '—') !== '—' ? (string) $it['party'] : null,
                        'note'   => trim((string) ($it['note'] ?? '')) ?: null,
                        'by_rep' => trim((string) ($it['by_rep'] ?? '')) ?: null,
                        'signed' => round((float) $it['signed'], 2),
                        'link'   => $it['link'] ?? null,
                        // مفتاح البند الأصلي — الإضافة اليدوية بس هي القابلة للتعديل من هنا
                        'type'   => (string) $it['type'],
                        'id'     => (int) $it['id'],
                    ];
                }
            }
        }

        /* ⚠️ ترتيب **مستقر**: التاريخ ثم ترتيب الإدراج. من غير الكسر الأخير صفوف
           نفس اليوم بتتنطط بين تحميل وتاني والرصيد الجاري يقرا مختلفًا كل فتحة. */
        $seq = 0;
        foreach ($rows as &$r) { $r['seq'] = $seq++; }
        unset($r);
        usort($rows, static fn ($a, $b) => [$a['date'], $a['seq']] <=> [$b['date'], $b['seq']]);

        $rowsTotal = count($rows);
        if ($rowsTotal > SafeInventory::MAX_ROWS) {
            $rows = array_slice($rows, 0, SafeInventory::MAX_ROWS);
        }

        // الرصيد الجاري من رصيد أول المدة — فآخر سطر بيساوي رصيد آخرها
        $run = $opening;
        foreach ($rows as &$r) { $run = round($run + $r['signed'], 2); $r['running'] = $run; }
        unset($r);

        return [
            'from' => $from, 'to' => $to, 'days' => $days,
            'opening' => $opening, 'closing' => $closing,
            'in'  => round((float) $move['in'], 2),
            'out' => round((float) $move['out'], 2),
            'net' => $net,
            'drift' => round($closing - $opening - $net, 2),
            'parts' => [
                'topups'   => round((float) $move['topups'], 2),
                'expenses' => round((float) $move['expenses'], 2),
                'driver'   => round((float) $move['driver'], 2),
            ],
            'rows' => $rows, 'capped' => $capped, 'rows_total' => $rowsTotal,
        ];
    }

    /** أيقونة قسم الحركة — ⚠️ كل اسم لازم يكون في خريطة `icon()` وإلا دايرة فاضية. */
    private static function groupIcon(string $group): string
    {
        return match ($group) {
            'topups'      => 'wallet',
            'permits'     => 'file-text',
            'general'     => 'trending-down',
            'custody'     => 'truck',
            'rep_deposit' => 'briefcase',
            default       => 'layers',
        };
    }

    /** الفترة الافتراضية للشاشة: الشهر الحالي (بيوم العمل لا بتاريخ الخادم). */
    public static function defaultRange(): array
    {
        $today = work_date();
        return [date('Y-m-01', strtotime($today)), $today];
    }

    // ─────────────────────────────────────────────── إدارة الإضافات اليدوية (توب-أب)

    public static function findTopup(int $id): ?array
    {
        return Database::row("SELECT * FROM manual_custody WHERE id = ?", [$id]);
    }

    public static function createTopup(array $d): int
    {
        Database::exec(
            "INSERT INTO manual_custody (cust_date, driver, amount, note)
             VALUES (:cust_date, :driver, :amount, :note)", self::bind($d));
        return Database::lastId();
    }

    public static function updateTopup(int $id, array $d): void
    {
        $p = self::bind($d);
        $p[':id'] = $id;
        Database::exec(
            "UPDATE manual_custody SET cust_date=:cust_date, driver=:driver,
                    amount=:amount, note=:note WHERE id=:id", $p);
    }

    public static function deleteTopup(int $id): void
    {
        Database::exec("DELETE FROM manual_custody WHERE id = ?", [$id]);
    }

    private static function bind(array $d): array
    {
        return [
            ':cust_date' => $d['cust_date'] ?: null,
            ':driver'    => ($d['driver'] ?? '') !== '' ? $d['driver'] : null,
            ':amount'    => (float) ($d['amount'] ?? 0),
            ':note'      => ($d['note'] ?? '') !== '' ? $d['note'] : null,
        ];
    }
}
