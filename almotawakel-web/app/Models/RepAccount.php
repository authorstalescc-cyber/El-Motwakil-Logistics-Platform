<?php
namespace App\Models;

use App\Core\App;

/**
 * حساب المندوب الشخصي (v72) — «حسابي» على بوابة الموبايل.
 *
 * ═══ اللزمة (طلب المالك 2026-08-10) ═══
 * «بعد ما المندوب يسجّل الدخول يظهرله اختيارين: الدخول لشاشة العمل أو حسابي.
 *  حسابي ده يظهرله راتبه، السلف اللي سحبها على مدار الشهر، والمصروفات الخاصة
 *  به زي المواصلات ومصروفات محددة هحددها من الإعدادات.»
 *
 * ═══ ⚠️⚠️ الوحدة دي **قراءة بحتة** — معزولة بالبناء ═══
 * مفيش فيها ولا `INSERT` ولا `UPDATE` ولا `DELETE`، ومفيش جدول جديد أصلاً.
 * كل رقم فيها **محسوب من مصادر قايمة**:
 *   • الراتب      → `reps.monthly_salary` (بيتظبّط من كارت المندوب في المكتب)
 *   • السلف       → `rep_misc_expenses` بوسم `advance` (v71)
 *   • المصروفات   → نفس الجدول بالأنواع اللي المالك بيختارها من الإعدادات
 * يعني الشاشة دي **ما بتحرّكش مليم**: مش بتخصم من خزنة ولا من عهدة ولا بتسجّل
 * مستحقًّا. هي عدسة على أرقام موجودة. لو اتقرّر بعدين إن الراتب يتصرف فعليًّا من
 * الخزنة، ده **قرار مالي جديد** ولازم جدول رواتب بتاريخ صرف — مش تعديل هنا.
 *
 * ═══ ⚠️ قرار المالك (سؤال مباشر 2026-08-10): السلف بس هي اللي بتتخصم ═══
 * **المواصلات ما بتتخصمش من راتبه** لأنها مصروف شركة بيتحاسب من حوض مصروف
 * المناديب — بتتعرض في الشاشة **للعلم بس**. والسؤال «أنهي نوع بيتخصم؟»
 * بيتجاوب من `RepMiscKind::deductsSalary()` لا من شرط مكتوب هنا (قاعدة ②).
 *
 * ═══ ⚠️ اتوسّع في v87 — «المتبقي» بقى فيه الحوضين ═══
 * «المتبقي» = الراتب − السلف **+ حصة الكومسيون + حصة المصروف**، والحصّتان
 * بييجوا من `Payroll::repShare()` (المصدر الوحيد) لا محسوبين هنا. قبل v87 كان
 * = الراتب − السلف بس، لأن الحوضين ما كانوش موجودين في النظام أصلاً.
 *
 * ═══ ⚠️ الفترة فترة رواتب مش شهر تقويمي ═══
 * قرار المالك: الشهر بيبدأ من يوم بيتحدّد من الإعدادات (`payroll_start_day`).
 * لو اليوم ٢٦، فترة «أغسطس» = من ٢٦ يوليو لـ٢٥ أغسطس.
 */
final class RepAccount
{
    /** يوم بداية شهر الرواتب (١..٢٨) — الإعداد معرَّف في `SettingsSchema`. */
    public const SETTING_START_DAY = 'payroll_start_day';

    /** أنواع النثرية اللي تظهر في قسم «مصروفاتي» — نص المالك بالعربي أو بالمفتاح. */
    public const SETTING_KINDS = 'rep_account_expense_kinds';

    /**
     * ⚠️⚠️ **الافتراضيان مكتوبان هنا عن قصد — مش تكرارًا للسجل المعرَّف.**
     * `App::setting` بترجّع الافتراضي اللي المستدعي باعته، و`Settings::get`
     * (اللي بتوصل افتراضي `SettingsSchema`) جزء من **وحدة سجل الإعدادات**.
     * الوحدة دي بتشتغل **سواء اتنشرت وحدة السجل أو لأ**، فبتقرا بالطريق
     * الأقدم المضمون وجوده. و`verify_rep_account` بيثبت إن القيمتين هنا
     * **مطابقتان** لافتراضي السجل لما يكون موجودًا — فمفيش تعريفان يختلفوا.
     */
    public const DEFAULT_START_DAY = '1';
    public const DEFAULT_KINDS     = 'مواصلات داخلية، مواصلات خارجية';

    /**
     * ⚠️ **الحد ٢٨ مقصود**: يوم ٢٩/٣٠/٣١ مالوش مقابل في كل شهر، ففترة بتبدأ
     *    يوم ٣١ كانت هتتخطّى فبراير بالكامل أو تزحلق نفسها شهرًا. ٢٨ أكبر يوم
     *    موجود في كل شهر بلا استثناء.
     */
    private const MAX_START_DAY = 28;

    public static function startDay(): int
    {
        /* ⚠️ الافتراضي **بيتبعت صراحةً**: `App::setting` بترجّع اللي بتبعتهوله
           وبس، ومن غيره كان بيرجع فاضي فالفترة تقع على يوم ١ بالسكوت. */
        $d = (int) App::setting(self::SETTING_START_DAY, self::DEFAULT_START_DAY);
        return max(1, min(self::MAX_START_DAY, $d ?: 1));
    }

    /**
     * فترة الرواتب اللي التاريخ ده واقع فيها.
     * @return array{from:string,to:string,label:string,key:string}
     */
    public static function period(string $anchor = ''): array
    {
        $anchor = $anchor !== '' && self::validDate($anchor) ? $anchor : date('Y-m-d');
        $day    = self::startDay();

        /* لو اليوم في التاريخ ده لسه ما وصلش يوم البداية، يبقى إحنا في الفترة
           اللي بدأت **الشهر اللي فات**. (٢٠ أغسطس مع بداية ٢٦ = فترة بدأت ٢٦ يوليو.) */
        $y = (int) substr($anchor, 0, 4);
        $m = (int) substr($anchor, 5, 2);
        if ((int) substr($anchor, 8, 2) < $day) {
            $m--; if ($m === 0) { $m = 12; $y--; }
        }
        $from = sprintf('%04d-%02d-%02d', $y, $m, $day);
        $to   = date('Y-m-d', strtotime($from . ' +1 month -1 day'));

        return ['from' => $from, 'to' => $to, 'label' => self::label($from, $to), 'key' => $from];
    }

    /** الفترة السابقة/اللاحقة — أساس أسهم التنقّل في الشاشة. */
    public static function shift(array $period, int $months): array
    {
        $sign = $months >= 0 ? '+' : '-';
        return self::period(date('Y-m-d', strtotime(
            $period['from'] . ' ' . $sign . abs($months) . ' month')));
    }

    /**
     * هل الفترة دي في المستقبل؟ (يمنع سهم «التالي» من فتح فترة لسه ما جتش)
     * ⚠️ المقارنة على `from` لا على `to`: فترة الشهر الجاي بتبدأ بعد النهاردة.
     */
    public static function isFuture(array $period): bool
    {
        return $period['from'] > date('Y-m-d');
    }

    private static function label(string $from, string $to): string
    {
        // «من ٢٦ يوليو لـ٢٥ أغسطس» — dfmt هو المصدر الوحيد لشكل التاريخ.
        return 'من ' . dfmt($from) . ' لـ' . dfmt($to);
    }

    private static function validDate(string $v): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
    }

    /**
     * الأنواع اللي المالك اختار تظهر في «مصروفاتي».
     *
     * ⚠️ **بتتفلتر من الكتالوج**: أي اسم مش معروف بيتشال بدل ما يخترع نوعًا،
     *    وأي نوع بيتخصم من الراتب بيتشال كمان — السلف ليها قسمها الخاص فوق
     *    وعرضها مرتين كان هيبان كأنها اتخصمت مرتين.
     * الإعداد الفاضي = **صفر أنواع** (القسم بيختفي) لا «كل الأنواع».
     *
     * @return string[]
     */
    public static function expenseKinds(): array
    {
        $raw = trim((string) App::setting(self::SETTING_KINDS, self::DEFAULT_KINDS));
        if ($raw === '') { return []; }

        $out = [];
        // الفاصلة الإنجليزية والعربية والسطر الجديد كلهم فواصل — المالك بيكتب بالعربي.
        foreach (preg_split('/[,\x{060C}\r\n]+/u', $raw) ?: [] as $part) {
            $key = RepMiscKind::resolve((string) $part);
            if ($key !== null && !RepMiscKind::deductsSalary($key) && !in_array($key, $out, true)) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /**
     * ملخّص فترة واحدة لمندوب واحد.
     *
     * @return array{salary:float,advances:float,commission:float,expenseShare:float,adjustments:float,approved:bool,net:float,expenses:array<string,float>,expensesTotal:float}
     */
    public static function summary(int $repId, array $period): array
    {
        $salary = round((float) (Rep::find($repId)['monthly_salary'] ?? 0), 2);

        /* ⚠️ السلف من **نفس الدالة** اللي التقارير بتقرا منها (`totalsByKind`)
           مش من استعلام موازٍ — تعريفان بيختلفوا = كارت يقول رقم وكشف يقول تاني. */
        $byKind   = RepMiscExpense::totalsByKind($repId, $period['from'], $period['to']);
        $advances = 0.0;
        foreach (RepMiscKind::salaryKeys() as $k) { $advances = round($advances + ($byKind[$k] ?? 0), 2); }

        $expenses = [];
        foreach (self::expenseKinds() as $k) { $expenses[$k] = round($byKind[$k] ?? 0, 2); }

        /* ⚠️ حصّتا الحوضين (v87) بييجوا من `Payroll` **مش محسوبين هنا**: نفس
           الدالة اللي شاشة المكتب بتقرا منها، فمستحيل موبايل المندوب يقول رقم
           والكشف يقول تاني. والفترة المعتمدة بترجّع اللقطة الثابتة. */
        $share = Payroll::repShare($repId, $period);

        return [
            'salary'        => $salary,
            'advances'      => $advances,
            'commission'    => $share['commission'],
            'expenseShare'  => $share['expense'],
            /* البنود اليدوية اللي المالك سجّلها في البروفايل (مكافأة · خصم ·
               جزاء) — موجب = زيادة له، سالب = خصم عليه (v88). */
            'adjustments'   => $share['adjust'],
            'approved'      => $share['approved'],
            /* ⚠️ المواصلات **مش** في المعادلة (قرار المالك) — بتتحاسب من حوض
               المصروف، واللي بيوصل المندوب منها هو `expenseShare` بس.
               ⚠️ و`adjustments` **لازم تفضل هنا**: من غيرها البروفايل بيعرض
                  جزاءً والصافي ما بيتغيّرش، وهي بالظبط مصيدة «كارت يقول رقم
                  وكشف يقول رقم تاني». */
            'net'           => round(
                $salary - $advances + $share['commission'] + $share['expense'] + $share['adjust'], 2),
            'expenses'      => $expenses,
            'expensesTotal' => round(array_sum($expenses), 2),
        ];
    }

    /** تفاصيل السلف في الفترة — «ممنوع رقم أصمّ»: الرقم بيفتح صفوفه. */
    public static function advanceRows(int $repId, array $period): array
    {
        return RepMiscExpense::rowsForKinds(
            $repId, RepMiscKind::salaryKeys(), $period['from'], $period['to']);
    }

    /** تفاصيل المصروفات المختارة في الفترة. */
    public static function expenseRows(int $repId, array $period): array
    {
        return RepMiscExpense::rowsForKinds(
            $repId, self::expenseKinds(), $period['from'], $period['to']);
    }

    /**
     * بنود لسه **تحت المراجعة** في الفترة — تحذير وظيفي مش شرح: الرقم فوق
     * بيحسب المعتمد بس، والمندوب لازم يعرف إن فيه حاجة لسه ما دخلتش.
     */
    public static function pendingCount(int $repId, array $period): int
    {
        return (int) \App\Core\Database::scalar(
            "SELECT COUNT(*) FROM rep_submissions
              WHERE rep_id = ? AND kind = 'misc' AND status = 'pending'
                AND entry_date BETWEEN ? AND ?"
            . RepCustody::epochSql('entry_date'),
            [$repId, $period['from'], $period['to']]);
    }
}
