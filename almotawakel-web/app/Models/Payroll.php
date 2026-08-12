<?php
namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Settings;
use RuntimeException;

/**
 * رواتب المناديب (v87) — **المصدر الوحيد** لأي رقم في كشف راتب المندوب.
 *
 * ═══ نموذج المالك (2026-08-11) ═══
 * راتب المندوب في الفترة = **٣ حاجات ما تتخلطش**:
 *
 *   ① **الراتب الثابت — فردي.** رقم على كارت المندوب. منه **بس** تتخصم السلف.
 *   ② **حوض الكومسيون — مشترك.** = Σ الـ١٠٠ المحتجزة من بوالص الفترة،
 *      يتقسم **بالتساوي**. («الـ١٠٠ بتاعتهم والإجمالي بيتقسم عليهم كلهم».)
 *   ③ **حوض المصروف — مشترك.** = (عدد البوالص × ١٥٠) − المصروف المسموح،
 *      و**الباقي** يتقسم بالتساوي. («هو مصروف لهم جميعًا على كل بوليصة».)
 *
 *   الصافي = ① − السلف + حصة ② + حصة ③
 *
 * ═══ ⚠️⚠️ ليه مفيش تحسيب مزدوج ═══
 * الفلوس اللي بتخرج فعلاً من الشركة تلاتة، وكل واحدة بتتسجّل **مرّة واحدة بس**:
 *   • **السلفة** خرجت يوم سحبها (`rep_misc_expenses` → `/expenses` → الخزنة، v42).
 *     هنا بتتسجّل `deduction` — **مش** `advance` — لأن `advance` من
 *     `Rep::CASH_KINDS` وكان هيخصمها من الخزنة **تاني**.
 *   • **المصروف المسموح** خرج يوم صرفه من عهدة المندوب. هنا بيتخصم من الحوض
 *     المشترك وبس، فالمصروف من الحوض = اللي اتصرف عينيًّا + الباقي كاش.
 *   • **الصافي** بيخرج يوم التسوية من قناة `rep_settlements` القايمة.
 * المحصّلة: إجمالي ما صرفته الشركة = الراتب + الحوضين بالظبط، ولا مليم مرتين.
 *
 * ═══ ⚠️ الفترة المعتمدة لقطة ثابتة ═══
 * كل رقم مشترك بيتخزّن في `payroll_periods` وكل حصة في `payroll_lines` **وقت
 * الاعتماد**. تغيير معدّل الـ١٠٠ أو الـ١٥٠ بعدين، أو تعديل بوليصة قديمة، **ما
 * بيلمسش** فترة معتمدة — زي فاتورة الإذن بالظبط.
 * وفوق ده المعدّلان **مؤرَّخان** (`Settings::DATED_KEYS`)، فحتى المسوّدة بتحسب
 * كل بوليصة بالمعدّل اللي كان ساريًا **يوم البوليصة** لا النهارده.
 *
 * ═══ ⚠️ حاجتان المالك لازم يعرفهما ═══
 *   ① **البوليصة اللي سيارتها معفاة** (`custody_deduction_exempt_cars`) عهدتها
 *      بتتخصم كاملة، يعني **ما احتجزتش ١٠٠** ⇒ بتدخل حوض المصروف (المندوب
 *      اشتغل عليها) و**ما بتدّيش كومسيون** (مفيش فلوس اتجمّعت منها أصلاً).
 *   ② **الكومسيون مصدره كاش قاعد في درج عهدة المكتب** (الـ١٠٠ اللي ما خرجتش
 *      للسائق). صرفه بيمرّ من قناة التسوية العادية زي أي مستحق — يعني
 *      **رصيد عهدة المكتب ما بينقصش تلقائيًّا**. لو المالك صرف الكومسيون من
 *      درج العهدة نفسه، لازم يسجّل السحب ده في وحدة العهدة بإيده.
 */
final class Payroll
{
    /** معدّل مصروف البوليصة (١٥٠) — **مؤرَّخ**. */
    public const SETTING_EXPENSE_RATE = 'rep_expense_per_bill';

    /** أنواع الشحنات اللي برّه الحساب كله (جامبو · تانك). */
    public const SETTING_EXCLUDED_TYPES = 'payroll_excluded_container_types';

    /** أنواع النثرية اللي بتتخصم من حوض المصروف. */
    public const SETTING_ALLOWED_KINDS = 'payroll_allowed_expense_kinds';

    /** الإعدادات الثلاثة — الترحيل والفاحص بيدوروا بيها بدل ما يكتبوها بإيدهم. */
    public const SETTINGS = [
        self::SETTING_EXPENSE_RATE,
        self::SETTING_EXCLUDED_TYPES,
        self::SETTING_ALLOWED_KINDS,
    ];

    /**
     * معدّل الكومسيون **هو نفسه** المخصوم من عهدة البوليصة — مش إعدادًا تانيًا.
     * لو بقى إعدادين، تغيير واحد فيهم كان هيدّي حوضًا مش موجود في الدرج.
     */
    public const SETTING_COMMISSION_RATE = 'custody_bill_deduction';

    /** قرار المدير لما المصروف يعدّي الحوض. */
    public const DEFICIT_OFFICE = 'office';   // المكتب يتحمّله
    public const DEFICIT_DEDUCT = 'deduct';   // يتقسم عليهم ويتخصم من رواتبهم
    public const DEFICIT_DECISIONS = [
        self::DEFICIT_OFFICE => 'المكتب يتحمّله',
        self::DEFICIT_DEDUCT => 'يتخصم منهم',
    ];

    /**
     * ⚠️⚠️ **المصدر الوحيد لنوع البند المتولَّد** — وأخطر سطر في الملف.
     * ممنوع أي مكان يكتب النوع بإيده، و`verify_payroll` بيفشل لو أي قيمة هنا
     * وقعت في `Rep::CASH_KINDS` (`advance`/`settlement`) — ساعتها الفلوس كانت
     * هتتخصم من الخزنة **مرتين**.
     */
    public const ENTRY_KINDS = [
        'base'       => 'salary',
        'commission' => 'bonus',
        'expense'    => 'transport',
        'advances'   => 'deduction',
        'deficit'    => 'deduction',
    ];

    /** بيان كل بند متولَّد (بيظهر في كشف حساب المندوب). */
    private const ENTRY_NOTES = [
        'base'       => 'راتب ثابت',
        'commission' => 'كومسيون البوالص',
        'expense'    => 'حصة مصروف البوالص',
        'advances'   => 'سلف الفترة (اتصرفت قبل كده)',
        'deficit'    => 'حصة الزيادة فوق حوض المصروف',
    ];

    // ─────────────────────────────────────────────── الجاهزية

    /** كاش الجاهزية — ⚠️ لازم يتصفّر في آخر أي ترحيل بيضيف الجداول. */
    private static ?bool $ready = null;

    /** هل الجداول موجودة؟ (يخلّي النظام يشتغل عادي قبل migrate_v87) */
    public static function available(): bool
    {
        return self::$ready ?? self::refreshReady();
    }

    /** بيصفّر الكاش الساكن ويقيس تاني — بيتنادى من آخر الترحيل (مصيدة موثَّقة). */
    public static function refreshReady(): bool
    {
        return self::$ready = (int) Database::scalar(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE()
                AND table_name IN ('payroll_periods','payroll_lines')") === 2;
    }

    // ─────────────────────────────────────────────── الإعدادات المقروءة

    /**
     * أنواع الشحنات المستثناة — مفاتيح مُوحَّدة بـ`normkey` جاهزة للمقارنة.
     * @return string[]
     */
    public static function excludedTypes(): array
    {
        $raw = (string) \App\Core\App::setting(self::SETTING_EXCLUDED_TYPES, "جامبو\nتانك");
        $out = [];
        foreach (preg_split('/[,\x{060C}\r\n]+/u', $raw) ?: [] as $part) {
            $k = Maintenance::normkey($part);
            if ($k !== '' && !in_array($k, $out, true)) { $out[] = $k; }
        }
        return $out;
    }

    /**
     * البوليصة دي برّه حساب المناديب؟
     *
     * ⚠️ المقارنة **باحتواء** لا بتطابق: التصنيف بيتكتب بأشكال («جامبو» ·
     *    «1*جامبو» · «جامبو 40») و`container_norm` بيشيل بادئة العدد بس.
     *    الاحتواء بيمسك التلاتة، والتطابق كان هيسيب اتنين منهم يدخلوا الحوض.
     * ⚠️ التوحيد بيمرّ على `container_norm` (المصدر الوحيد — v30) وبعده
     *    `normkey` عشان الهمزة والتاء المربوطة ما يفرقوش.
     */
    public static function isExcludedType(?string $containerType): bool
    {
        // ⚠️ `\` إجبارية: جوّه `App\Models` الاسم بيتحلّ لـ`App\Models\container_norm`.
        $norm = Maintenance::normkey((string) \container_norm($containerType));
        if ($norm === '') { return false; }   // بلا تصنيف = بوليصة عادية تدخل الحساب
        foreach (self::excludedTypes() as $bad) {
            if (str_contains($norm, $bad)) { return true; }
        }
        return false;
    }

    /**
     * أنواع النثرية اللي بتتخصم من حوض المصروف.
     * ⚠️ بتتفلتر من كتالوج `RepMiscKind` (الاسم المجهول بيتشال بدل ما يخترع
     *    نوعًا)، و**أي نوع بيتخصم من الراتب بيتشال** — السلفة ليها طريقها
     *    الخاص، وحسابها في الحوض كمان كان هيخصمها مرتين.
     * @return string[]
     */
    public static function allowedKinds(): array
    {
        $raw = trim((string) \App\Core\App::setting(
            self::SETTING_ALLOWED_KINDS, "مواصلات داخلية\nتصوير وطباعة"));
        if ($raw === '') { return []; }

        $out = [];
        foreach (preg_split('/[,\x{060C}\r\n]+/u', $raw) ?: [] as $part) {
            $key = RepMiscKind::resolve((string) $part);
            if ($key !== null && !RepMiscKind::deductsSalary($key) && !in_array($key, $out, true)) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /**
     * النثرية دي بتتحاسب من **حوض مصروف المناديب**؟ (v89)
     *
     * ⚠️ المصدر الوحيد للسؤال ده — ورقة التقفيل بتفصل بيه «مصروفات المناديب»
     *    عن «المصروفات العامة»، والحوض بيخصم بيه في `compute()`. لو اتكتب
     *    `in_array(..., allowedKinds())` بإيد أي ملف تاني، تغيير الإعداد كان
     *    هيدّي ورقة بتقول تصنيفًا والراتب بيقول تاني.
     * ⚠️ الوسم الفاضي (نثرية قبل v71) **مش** من الحوض: مفيش نوع مسجّل أصلاً.
     */
    public static function isAllowedKind(?string $kind): bool
    {
        $kind = trim((string) $kind);
        return $kind !== '' && in_array($kind, self::allowedKinds(), true);
    }

    // ─────────────────────────────────────────────── البنود اليدوية (v88)

    /**
     * أنواع البنود اليدوية اللي بتدخل حساب الفترة = **كل نوع مش كاش**.
     *
     * ⚠️ محسوبة من `Rep::KINDS` ناقص `Rep::CASH_KINDS` لا مكتوبة قايمة:
     *    أي نوع جديد (زي `penalty`) بيدخل تلقائيًّا. القايمة اليدوية كانت
     *    هتسيبه برّه الحساب **بالسكوت** فالبروفايل يقول رقم والراتب يقول تاني.
     * ⚠️ و**الكاش برّه عن قصد**: السلفة ودفعة التسوية حركتان نقديتان ليهما
     *    قناتهما (المصروفات والتقفيل) — حسابهما هنا كمان = تحسيب مزدوج.
     *
     * @return string[]
     */
    public static function adjustKinds(): array
    {
        return array_values(array_diff(array_keys(Rep::KINDS), Rep::CASH_KINDS));
    }

    /** `[sql, args]` لشرط البنود اليدوية بتاعة مندوب في فترة. */
    private static function adjustWhere(int $repId, string $from, string $to): array
    {
        $kinds = self::adjustKinds();
        $ph    = implode(',', array_fill(0, count($kinds), '?'));
        /* ⚠️ `payroll_period_id IS NULL` هي اللي بتمنع البنود اللي **الوحدة دي
           نفسها** ولّدتها إنها تتحسب تاني كتعديل يدوي — من غيرها الاعتماد
           بيضاعف نفسه في أي إعادة حساب. */
        return [
            'rep_id = ? AND payroll_period_id IS NULL AND kind IN (' . $ph . ')
             AND entry_date BETWEEN ? AND ?',
            array_merge([$repId], $kinds, [$from, $to]),
        ];
    }

    /** صافي البنود اليدوية في الفترة (موجب = زيادة له · سالب = خصم/جزاء). */
    public static function adjustmentsFor(int $repId, string $from, string $to): float
    {
        if (!self::available()) { return 0.0; }
        [$w, $args] = self::adjustWhere($repId, $from, $to);
        $credit = "'" . implode("','", Rep::CREDIT_KINDS) . "'";
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(CASE WHEN kind IN ($credit) THEN amount ELSE -amount END),0)
               FROM rep_entries WHERE $w", $args), 2);
    }

    /** صفوف البنود اليدوية — «ممنوع رقم أصمّ»: الرقم بيفتح بنوده. */
    public static function adjustmentRows(int $repId, string $from, string $to): array
    {
        if (!self::available()) { return []; }
        [$w, $args] = self::adjustWhere($repId, $from, $to);
        $rows = Database::all(
            "SELECT id, entry_date, kind, amount, note, is_visa, settlement_id
               FROM rep_entries WHERE $w
              ORDER BY entry_date DESC, id DESC", $args);
        foreach ($rows as &$r) {
            $r['label']  = Rep::kindLabel((string) $r['kind']);
            $r['signed'] = round(Rep::sign((string) $r['kind']) * (float) $r['amount'], 2);
        }
        unset($r);
        return $rows;
    }

    /**
     * **الفترة المعتمدة اللي التاريخ ده واقع فيها** — أو `null`.
     *
     * ⚠️ ده حارس الكتابة (قاعدة ③): بند يتزوّد أو يتشال جوّه فترة **اتعتمدت
     *    خلاص** بيخلّي لقطة الراتب تقول رقم وسجل المندوب يقول رقم تاني، وهي
     *    نفس مصيدة «كارت يقول رقم وكشف يقول تاني». الحل: يترفض، والمالك
     *    يلغي الاعتماد لو عايز يعدّل.
     */
    public static function lockOn(?string $date): ?array
    {
        if (!self::available() || $date === null || $date === '') { return null; }
        return Database::row(
            "SELECT * FROM payroll_periods
              WHERE status = 'approved' AND ? BETWEEN period_from AND period_to
              LIMIT 1", [$date]);
    }

    /** رسالة الرفض الجاهزة — نص واحد فمستحيل شاشتان تقولا سببين مختلفين. */
    public static function lockMessage(?string $date): ?string
    {
        $p = self::lockOn($date);
        return $p === null ? null
            : 'راتب الفترة (' . dfmt((string) $p['period_from']) . ' – '
            . dfmt((string) $p['period_to']) . ') اتعتمد خلاص — الغِ الاعتماد الأول.';
    }

    // ─────────────────────────────────────────────── القسمة بالتساوي

    /**
     * قسمة مبلغ على `$n` **بلا ما يضيع ولا قرش**.
     *
     * ⚠️ `round($total/$n, 2)` مضروبة في `$n` **مش** بترجّع `$total`:
     *    ١٠٠٠ ÷ ٣ = ٣٣٣٫٣٣ × ٣ = ٩٩٩٫٩٩ — قرش بيتبخّر كل فترة. عشان كده
     *    القسمة بالقروش صحيحة والباقي بيتوزّع قرشًا قرشًا على الأوائل، و
     *    `verify_payroll` بيثبت إن Σ الحصص = الحوض **بالظبط**.
     *
     * @return float[] `$n` حصة مجموعها = `$total` تمامًا
     */
    public static function split(float $total, int $n): array
    {
        if ($n <= 0) { return []; }
        $neg    = $total < 0;
        $cents  = (int) round(abs($total) * 100);
        $base   = intdiv($cents, $n);
        $extra  = $cents - ($base * $n);

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $c = $base + ($i < $extra ? 1 : 0);
            $out[] = round(($neg ? -$c : $c) / 100, 2);
        }
        return $out;
    }

    // ─────────────────────────────────────────────── الحوضان (من البوالص)

    /**
     * مسح بوالص الفترة → الحوضان + العدّ.
     *
     * ⚠️ المسح **في PHP مش في SQL** عن قصد: استثناء نوع الشحنة بيمرّ على
     *    `container_norm()` (دالة PHP، المصدر الوحيد للتوحيد)، وترجمتها لـSQL
     *    كانت هتبقى تعريفًا تانيًا بيختلف عنها بالسكوت. عدد بوالص الشهر
     *    بالمئات — الفرق في الأداء مش موجود، والفرق في الصحّة كبير.
     *
     * @return array{counted:int,excluded:int,commission:float,expense:float}
     */
    /** @return array<int,array<string,mixed>> كل بوليصة + `excluded`/`retained`/`rate` */
    public static function billRows(string $from, string $to): array
    {
        $rows = Database::all(
            "SELECT id, bill_number, bill_date, company, custody, car_no, container_type
               FROM bawalis
              WHERE bill_date BETWEEN ? AND ?
              ORDER BY bill_date, id", [$from, $to]);

        foreach ($rows as &$r) {
            $on = (string) ($r['bill_date'] ?? '');
            $on = $on !== '' ? $on : null;
            $r['excluded'] = self::isExcludedType($r['container_type'] ?? null);
            // المحتجز من البوليصة — المصدر الوحيد (Custody::retained).
            $r['retained'] = $r['excluded'] ? 0.0
                : Custody::retained((float) $r['custody'], $r['car_no'] ?? null, $on);
            // معدّل المصروف السارى **يوم البوليصة** لا النهارده.
            $r['rate']     = $r['excluded'] ? 0.0
                : round((float) Settings::valueOn(self::SETTING_EXPENSE_RATE, $on), 2);
        }
        unset($r);
        return $rows;
    }

    /** @return array{counted:int,excluded:int,commission:float,expense:float} */
    public static function billScan(string $from, string $to): array
    {
        $counted = 0; $excluded = 0; $commission = 0.0; $expense = 0.0;
        foreach (self::billRows($from, $to) as $r) {
            if ($r['excluded']) { $excluded++; continue; }
            $counted++;
            $commission += (float) $r['retained'];
            $expense    += (float) $r['rate'];
        }

        return [
            'counted'    => $counted,
            'excluded'   => $excluded,
            'commission' => round($commission, 2),
            'expense'    => round($expense, 2),
        ];
    }

    // ─────────────────────────────────────────────── حساب الفترة كاملة

    /**
     * حساب فترة (مسوّدة) — مفيش أي كتابة هنا.
     *
     * @param array  $period  مخرَج `RepAccount::period()`
     * @param int[]  $exclude أرقام مناديب **برّه قسمة الحوضين** (لسه بياخدوا
     *                        راتبهم الثابت ناقص سلفهم — الاستبعاد عن الحوض بس)
     * @param string $deficit قرار المدير لو المصروف عدّى الحوض
     */
    public static function compute(array $period, array $exclude = [], string $deficit = self::DEFICIT_OFFICE): array
    {
        $from = $period['from']; $to = $period['to'];
        if (!isset(self::DEFICIT_DECISIONS[$deficit])) { $deficit = self::DEFICIT_OFFICE; }

        $pools = self::billScan($from, $to);
        $allow = self::allowedKinds();

        /* ⚠️ **كل المناديب بيتمسحوا مش النشطين بس**: المندوب اللي وقف الشهر ده
           سلفه ومصروفه استهلكوا الحوض فعلاً ولازم يبانوا. بس اللي **بياخد
           حصة** هو النشط غير المستبعَد يدويًّا — واللي مالوش ولا حركة ولا هو
           نشط بيتشال خالص من الكشف بدل سطر أصفار. */
        $lines = []; $allowedTotal = 0.0;
        foreach (Rep::all('', '') as $r) {
            $id     = (int) $r['id'];
            $active = ($r['status'] ?? '') === 'active';
            $byKind = RepMiscExpense::totalsByKind($id, $from, $to);

            $advances = 0.0;
            foreach (RepMiscKind::salaryKeys() as $k) { $advances += (float) ($byKind[$k] ?? 0); }

            $allowed = 0.0;
            foreach ($allow as $k) { $allowed += (float) ($byKind[$k] ?? 0); }

            /* البنود اليدوية (مكافأة · خصم · جزاء) اللي المالك سجّلها في
               بروفايل المندوب داخل الفترة — بتدخل الصافي زي ما هي. */
            $adjust = self::adjustmentsFor($id, $from, $to);

            if (!$active && $advances <= 0 && $allowed <= 0 && abs($adjust) < 0.005) { continue; }

            /* ⚠️ بيتجمّع من **كل** اللي في الكشف حتى المستبعَد من القسمة:
               المصروف ده اتصرف فعلاً من الحوض المشترك سواء صاحبه هياخد
               حصة ولا لأ. */
            $allowedTotal += $allowed;

            $lines[] = [
                'rep_id'      => $id,
                'rep_name'    => (string) $r['name'],
                'included'    => $active && !in_array($id, $exclude, true),
                'active'      => $active,
                'base_salary' => $active ? round((float) ($r['monthly_salary'] ?? 0), 2) : 0.0,
                'advances'    => round($advances, 2),
                'allowed'     => round($allowed, 2),
                'adjustments' => $adjust,
            ];
        }
        $allowedTotal = round($allowedTotal, 2);

        // ── الحوضان بعد الخصم
        $remainder = round($pools['expense'] - $allowedTotal, 2);
        $deficitAmt = 0.0;
        if ($remainder < 0) { $deficitAmt = round(-$remainder, 2); $remainder = 0.0; }

        $in = array_values(array_filter($lines, static fn ($l) => $l['included']));
        $n  = count($in);

        $commShares = self::split($pools['commission'], $n);
        $expShares  = $deficitAmt > 0 && $deficit === self::DEFICIT_DEDUCT
            ? self::split(-$deficitAmt, $n)     // بالسالب — بيقلّل الصافي
            : self::split($remainder, $n);

        $i = 0;
        foreach ($lines as &$l) {
            $l['commission_share'] = $l['included'] ? ($commShares[$i] ?? 0.0) : 0.0;
            $l['expense_share']    = $l['included'] ? ($expShares[$i]  ?? 0.0) : 0.0;
            if ($l['included']) { $i++; }
            $l['net'] = round(
                $l['base_salary'] - $l['advances'] + $l['commission_share']
                + $l['expense_share'] + $l['adjustments'], 2);
        }
        unset($l);

        return [
            'period'            => $period,
            'bills_counted'     => $pools['counted'],
            'bills_excluded'    => $pools['excluded'],
            'commission_pool'   => $pools['commission'],
            'expense_pool'      => $pools['expense'],
            'allowed_expenses'  => $allowedTotal,
            'expense_remainder' => $remainder,
            'deficit'           => $deficitAmt,
            'deficit_decision'  => $deficit,
            'reps_count'        => $n,
            'allowed_kinds'     => $allow,
            'lines'             => $lines,
            'net_total'         => round(array_sum(array_column($lines, 'net')), 2),
        ];
    }

    // ─────────────────────────────────────────────── الفترات المحفوظة

    public static function findPeriod(string $from, string $to): ?array
    {
        if (!self::available()) { return null; }
        return Database::row(
            'SELECT * FROM payroll_periods WHERE period_from = ? AND period_to = ?', [$from, $to]);
    }

    public static function findPeriodById(int $id): ?array
    {
        if (!self::available()) { return null; }
        return Database::row('SELECT * FROM payroll_periods WHERE id = ?', [$id]);
    }

    /** سطور فترة معتمدة (اللقطة الثابتة). */
    public static function lines(int $periodId): array
    {
        if (!self::available()) { return []; }
        return Database::all(
            'SELECT * FROM payroll_lines WHERE period_id = ? ORDER BY included DESC, id', [$periodId]);
    }

    /** آخر الفترات المعتمدة (للكارت الجانبي). */
    public static function recent(int $limit = 12): array
    {
        if (!self::available()) { return []; }
        return Database::all(
            'SELECT * FROM payroll_periods ORDER BY period_from DESC LIMIT ' . max(1, min(60, $limit)));
    }

    // ─────────────────────────────────────────────── الاعتماد

    /**
     * اعتماد فترة: لقطة ثابتة + بنود استحقاق في سجل كل مندوب.
     *
     * ⚠️ **الحُرّاس هنا في الموديل مش في الشاشة** (قاعدة ③): الفترة المعتمدة
     *    مرة تانية، والنطاق المقلوب، وقرار عجز مش معروف — كلهم بيترفضوا هنا
     *    مهما كان الفورم بيقول إيه.
     * ⚠️ **مفيش `Closing::guard`** عن قصد: البنود المتولَّدة كلها استحقاقات
     *    (`salary`/`bonus`/`transport`/`deduction`) و**ولا واحد منهم بيمسّ
     *    خزنة** — الخزنة بتتحرّك ساعة التسوية بس. حارس تقفيل هنا كان هيمنع
     *    اعتماد فترة قديمة بلا أي سبب مالي.
     *
     * @return int رقم الفترة
     * @throws RuntimeException
     */
    public static function approve(array $period, array $exclude = [], string $deficit = self::DEFICIT_OFFICE, string $note = ''): int
    {
        if (!self::available()) {
            throw new RuntimeException('جداول الرواتب مش موجودة — شغّل bin/migrate_v87.php.');
        }
        $from = (string) $period['from']; $to = (string) $period['to'];
        if ($from === '' || $to === '' || $from > $to) {
            throw new RuntimeException('نطاق الفترة غلط.');
        }
        if (self::findPeriod($from, $to)) {
            throw new RuntimeException('الفترة دي متعمدة قبل كده — امسح الاعتماد الأول لو عايز تعيده.');
        }

        $calc = self::compute($period, $exclude, $deficit);
        if ($calc['reps_count'] < 1) {
            throw new RuntimeException('مفيش مندوب داخل القسمة — الفترة مش هتعمل حاجة.');
        }

        /* ⚠️ **المعاملة متداخلة الأمان**: `beginTransaction` جوّه معاملة مفتوحة
           بترمي `PDOException` (اتصادف في الفاحص فعلاً). فلو المستدعي فاتح
           معاملة بالفعل — فاحص أو ترحيل — بنشتغل جوّاها وبنسيب التقرير له. */
        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            Database::exec(
                "INSERT INTO payroll_periods
                    (period_from, period_to, status, bills_counted, bills_excluded,
                     commission_pool, expense_pool, allowed_expenses, expense_remainder,
                     deficit, deficit_decision, reps_count, note, approved_at, approved_by)
                 VALUES (:f, :t, 'approved', :bc, :bx, :cp, :ep, :ae, :er, :df, :dd, :rc, :note, NOW(), :by)",
                [
                    ':f' => $from, ':t' => $to,
                    ':bc' => $calc['bills_counted'], ':bx' => $calc['bills_excluded'],
                    ':cp' => $calc['commission_pool'], ':ep' => $calc['expense_pool'],
                    ':ae' => $calc['allowed_expenses'], ':er' => $calc['expense_remainder'],
                    ':df' => $calc['deficit'], ':dd' => $calc['deficit_decision'],
                    ':rc' => $calc['reps_count'],
                    ':note' => $note !== '' ? $note : null,
                    ':by' => Auth::user(),
                ]);
            $pid = Database::lastId();

            foreach ($calc['lines'] as $l) {
                Database::exec(
                    "INSERT INTO payroll_lines
                        (period_id, rep_id, rep_name, included, base_salary, advances,
                         commission_share, expense_share, adjustments, net)
                     VALUES (:p, :r, :n, :i, :b, :a, :c, :e, :adj, :net)",
                    [
                        ':p' => $pid, ':r' => $l['rep_id'], ':n' => $l['rep_name'],
                        ':i' => $l['included'] ? 1 : 0,
                        ':b' => $l['base_salary'], ':a' => $l['advances'],
                        ':c' => $l['commission_share'], ':e' => $l['expense_share'],
                        ':adj' => $l['adjustments'], ':net' => $l['net'],
                    ]);

                self::entry($pid, $l['rep_id'], $to, 'base',       $l['base_salary']);
                self::entry($pid, $l['rep_id'], $to, 'commission', $l['commission_share']);
                self::entry($pid, $l['rep_id'], $to, 'advances',   $l['advances']);
                if ($l['expense_share'] >= 0) {
                    self::entry($pid, $l['rep_id'], $to, 'expense', $l['expense_share']);
                } else {
                    self::entry($pid, $l['rep_id'], $to, 'deficit', -$l['expense_share']);
                }
            }

            if ($own) { $pdo->commit(); }
            return $pid;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /**
     * بند واحد في سجل المندوب — **الطريق الوحيد** اللي الوحدة دي بتكتب بيه في
     * `rep_entries`. الصفر ما بيتسجّلش (سطر بصفر بيوسّخ الكشف بلا معلومة).
     */
    private static function entry(int $periodId, int $repId, string $date, string $slot, float $amount): void
    {
        $amount = round($amount, 2);
        if ($amount <= 0) { return; }
        Database::exec(
            "INSERT INTO rep_entries (rep_id, entry_date, kind, amount, is_visa, note, payroll_period_id)
             VALUES (:r, :d, :k, :a, 0, :n, :p)",
            [
                ':r' => $repId, ':d' => $date,
                ':k' => self::ENTRY_KINDS[$slot],
                ':a' => $amount,
                ':n' => self::ENTRY_NOTES[$slot],
                ':p' => $periodId,
            ]);
    }

    /**
     * إلغاء اعتماد فترة — بيشيل بنودها من سجل كل مندوب كمان.
     *
     * ⚠️ **بيرفض** لو أي بند من بنودها اتحاسب في تقفيل (`settlement_id`):
     *    ساعتها الفلوس خرجت فعلاً، وشيل البند كان هيسيب تسوية مدفوعة بلا
     *    استحقاق يقابلها فيتقلب رصيد المندوب رأسًا على عقب.
     */
    public static function unapprove(int $periodId): void
    {
        if (!self::available()) { return; }
        $settled = (int) Database::scalar(
            'SELECT COUNT(*) FROM rep_entries WHERE payroll_period_id = ? AND settlement_id IS NOT NULL',
            [$periodId]);
        if ($settled > 0) {
            throw new RuntimeException('فيه بنود من الفترة دي اتحاسبت في تقفيل — مينفعش تتلغي.');
        }

        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            Database::exec('DELETE FROM rep_entries  WHERE payroll_period_id = ?', [$periodId]);
            Database::exec('DELETE FROM payroll_lines WHERE period_id = ?', [$periodId]);
            Database::exec('DELETE FROM payroll_periods WHERE id = ?', [$periodId]);
            if ($own) { $pdo->commit(); }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    // ─────────────────────────────────────────────── قراءة لشاشة المندوب

    /**
     * حصة مندوب واحد في فترة — **معتمدة لو الفترة اتعتمدت، محسوبة لو لأ**.
     * شاشة «حسابي» بتقرا من هنا فمستحيل الكارت يقول رقم والكشف يقول تاني.
     *
     * @return array{commission:float,expense:float,adjust:float,approved:bool}
     */
    public static function repShare(int $repId, array $period): array
    {
        if (!self::available()) {
            return ['commission' => 0.0, 'expense' => 0.0, 'adjust' => 0.0, 'approved' => false];
        }

        $row = self::findPeriod($period['from'], $period['to']);
        if ($row) {
            $line = Database::row(
                'SELECT * FROM payroll_lines WHERE period_id = ? AND rep_id = ?',
                [(int) $row['id'], $repId]);
            return [
                'commission' => round((float) ($line['commission_share'] ?? 0), 2),
                'expense'    => round((float) ($line['expense_share'] ?? 0), 2),
                'adjust'     => round((float) ($line['adjustments'] ?? 0), 2),
                'approved'   => true,
            ];
        }

        foreach (self::compute($period)['lines'] as $l) {
            if ($l['rep_id'] === $repId) {
                return [
                    'commission' => (float) $l['commission_share'],
                    'expense'    => (float) $l['expense_share'],
                    'adjust'     => (float) $l['adjustments'],
                    'approved'   => false,
                ];
            }
        }
        return ['commission' => 0.0, 'expense' => 0.0, 'adjust' => 0.0, 'approved' => false];
    }
}
